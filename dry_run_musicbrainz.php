<?php

/*
 * DRY-RUN VOOR MUSICBRAINZ MATCHING
 *
 * Dit script zoekt MusicBrainz-kandidaten voor actieve Festivalinfo-artiesten,
 * beoordeelt de beste kandidaat met meerdere signalen en schrijft de uitkomst
 * naar de tijdelijke workflowtabellen. Er wordt NIET naar platform_link geschreven.
 */
require_once 'MusicBrainzClient.php';

// Maak verbinding met de lokale Festivalinfo-testdatabase.
$pdo = new PDO(
  'mysql:host=localhost;dbname=festivalinfo-test;charset=utf8mb4',
  'root',
  ''
);

// Laat PDO databasefouten als exceptions gooien.
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Maak één MusicBrainzClient aan voor alle API-aanroepen in dit script.
$client = new MusicBrainzClient();

// Geef deze volledige dry-run een uniek ID voor logging en audit.
$runId = 'dryrun_' . date('Ymd_His');

// Normaliseer een artiestennaam zodat kleine leestekenverschillen niet meetellen.
function normalizeArtistName(string $name): string
{
  $name = mb_strtolower($name);
  $name = str_replace(["'", "’", ".", ",", "-", "_"], "", $name);

  return preg_replace('/\s+/', ' ', trim($name));
}

// Herken samengestelde namen zoals 'A feat. B' of 'A vs. B'.
function isCompositeName(string $name): bool
{
  $patterns = [
    ' feat. ',
    ' feat ',
    ' ft. ',
    ' ft ',
    ' featuring ',
    ' vs. ',
    ' vs '
  ];

  $lower = mb_strtolower($name);

  foreach ($patterns as $pattern) {
    if (str_contains($lower, $pattern)) {
      return true;
    }
  }

  return false;
}

// Bouw veilige alternatieve zoekvarianten zonder betekenisvolle woorden blind te verwijderen.
function getSearchVariants(string $artistName): array
{
  $variants = [$artistName];

  $cleaned = preg_replace(
    '/\s*\(([A-Z]{2})\)\s*$/u',
    '',
    $artistName
  );

  $cleaned = str_replace(["'", "’"], '', $cleaned);
  $cleaned = preg_replace('/\s+/', ' ', trim($cleaned));

  if ($cleaned !== '' && $cleaned !== $artistName) {
    $variants[] = $cleaned;
  }

  return array_values(array_unique($variants));
}

// Controleer of deze MBID al aan een andere Festivalinfo-artiest gekoppeld is.
function hasDuplicateExternalId(
  PDO $pdo,
  int $artistId,
  string $mbid
): bool {
  $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM platform_link
        WHERE entity_type_id = 1
          AND platform_id = 6
          AND external_identifier = ?
          AND entity_id <> ?
    ");

  $stmt->execute([$mbid, $artistId]);

  return (int)$stmt->fetchColumn() > 0;
}

// Haal een bestaande Spotify-ID voor deze artiest op uit platform_link.
function getExistingSpotifyId(
  PDO $pdo,
  int $artistId
): ?string {
  $stmt = $pdo->prepare("
        SELECT external_identifier
        FROM platform_link
        WHERE entity_type_id = 1
          AND entity_id = ?
          AND platform_id = 2
        LIMIT 1
    ");

  $stmt->execute([$artistId]);

  $spotifyId = $stmt->fetchColumn();

  return $spotifyId !== false
    ? (string)$spotifyId
    : null;
}

// Controleer of MusicBrainz naar dezelfde Spotify-artiest verwijst.
function musicBrainzHasSpotifyId(
  array $details,
  string $spotifyId
): bool {
  foreach ($details['relations'] ?? [] as $relation) {

    $resource =
      $relation['url']['resource']
      ?? '';

    if (
      str_contains(
        $resource,
        'open.spotify.com/artist/'
      )
      && str_contains(
        $resource,
        $spotifyId
      )
    ) {
      return true;
    }
  }

  return false;
}

// Selecteer eerst artiesten met toekomstige optredens die nog geen MusicBrainz-link hebben.
$sql = "
WITH event_stats AS (
    SELECT
        a2e.artist2event_artist_id AS artist_id,
        SUM(
            CASE
                WHEN e.event_date_time >= NOW()
                THEN 1
                ELSE 0
            END
        ) AS future_count
    FROM artist2event a2e
    JOIN event e
        ON e.event_id =
           a2e.artist2event_event_id
    GROUP BY
        a2e.artist2event_artist_id
),
festival_stats AS (
    SELECT
        f2a.artist_id,
        SUM(
            CASE
                WHEN f.festival_start_date >= CURRENT_DATE
                THEN 1
                ELSE 0
            END
        ) AS future_count
    FROM festival2artist f2a
    JOIN festival f
        ON f.festival_id =
           f2a.festival_id
    WHERE f.festival_afgelast = 0
    GROUP BY f2a.artist_id
)
SELECT
    a.artist_id,
    a.artist_name,

    COALESCE(e.future_count, 0)
    + COALESCE(f.future_count, 0)
        AS future_count

FROM artist a

LEFT JOIN event_stats e
    ON e.artist_id = a.artist_id

LEFT JOIN festival_stats f
    ON f.artist_id = a.artist_id

LEFT JOIN platform_link mb
    ON mb.entity_type_id = 1
   AND mb.entity_id = a.artist_id
   AND mb.platform_id = 6

WHERE
    COALESCE(e.future_count, 0)
    + COALESCE(f.future_count, 0) > 0

AND mb.platform_link_id IS NULL

ORDER BY
    future_count DESC,
    a.artist_id

LIMIT 20
";

$artists =
  $pdo->query($sql)
  // Zet de gevonden rijen om naar associatieve arrays.
  ->fetchAll(PDO::FETCH_ASSOC);

echo "<h1>MusicBrainz dry-run</h1>";

echo "
<p>
<strong>DRY RUN:</strong>
er wordt niets naar platform_link geschreven.
</p>
";

echo "Aantal geselecteerde artiesten: "
  . count($artists)
  . "<hr>";

// Houd per status bij hoeveel artiesten deze run oplevert.
$metrics = [
  'matched' => 0,
  'review' => 0,
  'no_match' => 0,
  'retry' => 0
];

// Verwerk iedere geselecteerde artiest afzonderlijk.
foreach ($artists as $artist) {

  $artistId =
    (int)$artist['artist_id'];

  $artistName =
    $artist['artist_name'];

  $futureCount =
    (int)$artist['future_count'];

  echo "<h2>"
    . htmlspecialchars($artistName)
    . "</h2>";

  echo "Artist ID: {$artistId}<br>";
  echo "Future count: {$futureCount}<br>";

  $composite =
    isCompositeName($artistName);

  try {

    $candidates = [];
    $usedQuery = null;

    foreach (
      getSearchVariants($artistName)
      as $queryVariant
    ) {

      $response =
        // Vraag MusicBrainz om zoekkandidaten.
        $client->searchArtist(
          $queryVariant,
          3
        );

      $found =
        $response['artists']
        ?? [];

      if (!empty($found)) {

        $candidates = $found;
        $usedQuery = $queryVariant;

        break;
      }
    }

    echo "Gebruikte query: "
      . htmlspecialchars(
        $usedQuery ?? 'geen'
      )
      . "<br>";

    // Als er geen kandidaten zijn, bepaal dan een veilige fallbackstatus.
    if (empty($candidates)) {

      $decision =
        $composite
        ? 'review'
        : 'no_match';

      $metrics[$decision]++;

      echo "Geen kandidaten gevonden.<br>";

      echo "<strong>
                DRY-RUN BESLUIT:
                {$decision}
            </strong><hr>";

      continue;
    }

    // Neem de hoogst gerangschikte MusicBrainz-kandidaat als kandidaat #1.
    $best = $candidates[0];

    $bestMbid =
      $best['id'] ?? null;

    if (!$bestMbid) {

      $decision = 'review';
      $metrics[$decision]++;

      echo "
            <strong>
            DRY-RUN BESLUIT: review
            </strong><br>
            ";

      echo "
            Reden:
            kandidaat heeft geen MBID.
            <hr>
            ";

      continue;
    }

    $bestScore =
      (float)($best['score'] ?? 0);

    // Kijk of er een tweede kandidaat is om het scoreverschil te kunnen berekenen.
    $hasSecondCandidate =
      isset($candidates[1]);

    $secondScore =
      // Kijk of er een tweede kandidaat is om het scoreverschil te kunnen berekenen.
      $hasSecondCandidate
      ? (float)(
        $candidates[1]['score']
        ?? 0
      )
      : null;

    // Bereken de afstand tussen kandidaat #1 en kandidaat #2.
    $margin =
      // Kijk of er een tweede kandidaat is om het scoreverschil te kunnen berekenen.
      $hasSecondCandidate
      ? $bestScore - $secondScore
      : null;

    $details =
      // Haal extra kandidaatdetails op, zoals aliassen en URL-relaties.
      $client->getArtist(
        $bestMbid
      );

    $bestName =
      $details['name']
      ?? '';

    // Controleer of de genormaliseerde namen exact overeenkomen.
    $exactName =
      normalizeArtistName(
        $artistName
      )
      ===
      normalizeArtistName(
        $bestName
      );

    // Start met de aanname dat er nog geen alias-match is gevonden.
    $aliasMatch = false;

    foreach (
      $details['aliases'] ?? []
      as $alias
    ) {

      if (
        normalizeArtistName(
          $alias['name']
            ?? ''
        )
        ===
        normalizeArtistName(
          $artistName
        )
      ) {

        $aliasMatch = true;
        break;
      }
    }

    // Voorkom dat dezelfde MBID stil aan twee Festivalinfo-artiesten wordt gekoppeld.
    $duplicateExternalId =
      hasDuplicateExternalId(
        $pdo,
        $artistId,
        $bestMbid
      );

    $existingSpotifyId =
      getExistingSpotifyId(
        $pdo,
        $artistId
      );

    // Gebruik een gedeelde Spotify-relatie als onafhankelijk extra bewijs.
    $spotifyRelationMatch =
      $existingSpotifyId !== null
      &&
      musicBrainzHasSpotifyId(
        $details,
        $existingSpotifyId
      );

    // Verzamel het onafhankelijke bewijs dat nodig is voor een automatische match.
    $independentSignal =
      $spotifyRelationMatch;

    // Begin de beslislogica: risicovolle samengestelde namen gaan altijd naar review.
    if ($composite) {

      $decision = 'review';

      $reason =
        'samengestelde artiestennaam';
    } elseif ($duplicateExternalId) {

      $decision = 'review';

      $reason =
        'MBID is al gekoppeld aan een andere artiest';
    } elseif (
      ($exactName || $aliasMatch)
      && (
        !$hasSecondCandidate
        || $margin >= 15
      )
      && $independentSignal
    ) {

      $decision = 'matched';

      $reason =
        'naam/alias + margin + Spotify-signaal';
    } else {

      $decision = 'review';

      $reason =
        'onvoldoende bewijs voor automatische match';
    }

    $metrics[$decision]++;

    // Bewaar de belangrijkste matchsignalen als JSON voor latere uitleg en audit.
    $evidence = json_encode([
      'exact_name' => $exactName,
      'alias_match' => $aliasMatch,
      'margin_to_second' => $margin,
      'duplicate_external_id' => $duplicateExternalId,
      'spotify_relation_match' => $spotifyRelationMatch,
      'composite_name' => $composite
    ], JSON_UNESCAPED_UNICODE);

    // Sla de beste gevonden kandidaat op in de generieke candidate-tabel.
    $insertCandidate = $pdo->prepare("
      INSERT INTO artist_external_match_candidate (
        artist_id,
        platform_id,
        external_identifier,
        candidate_name,
        candidate_disambiguation,
        source_method,
        provider_rank,
        provider_score,
        evidence,
        status,
        query_variant,
        run_id
      )
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        candidate_name = VALUES(candidate_name),
        candidate_disambiguation = VALUES(candidate_disambiguation),
        provider_rank = VALUES(provider_rank),
        provider_score = VALUES(provider_score),
        evidence = VALUES(evidence),
        status = VALUES(status),
        query_variant = VALUES(query_variant),
        observed_at = CURRENT_TIMESTAMP,
        run_id = VALUES(run_id)
    ");

    $insertCandidate->execute([
      $artistId,
      6,
      $bestMbid,
      $bestName,
      $details['disambiguation'] ?? null,
      'musicbrainz_search',
      1,
      $bestScore,
      $evidence,
      'active',
      $usedQuery,
      $runId
    ]);

    // Lees de candidate_id van de zojuist opgeslagen kandidaat uit.
    $candidateId = (int)$pdo->lastInsertId();

    // Bij een bestaande kandidaat levert lastInsertId() soms 0 op; zoek hem dan opnieuw op.
    if ($candidateId === 0) {
      $candidateLookup = $pdo->prepare("
        SELECT candidate_id
        FROM artist_external_match_candidate
        WHERE artist_id = ?
          AND platform_id = ?
          AND external_identifier = ?
          AND source_method = ?
        LIMIT 1
      ");

      $candidateLookup->execute([
        $artistId,
        6,
        $bestMbid,
        'musicbrainz_search'
      ]);

      $candidateId = (int)$candidateLookup->fetchColumn();
    }

    $decisionValue =
      $decision === 'matched'
      ? 'selected'
      : $decision;

    // Leg het genomen besluit append-only vast in de decision/audit-tabel.
    $insertDecision = $pdo->prepare("
      INSERT INTO artist_external_match_decision (
        artist_id,
        platform_id,
        candidate_id,
        decision,
        previous_status,
        new_status,
        actor_type,
        actor_name,
        reason,
        platform_link_id,
        run_id
      )
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $insertDecision->execute([
      $artistId,
      6,
      $candidateId ?: null,
      $decisionValue,
      'unprocessed',
      $decision,
      'system',
      'dry_run_musicbrainz',
      $reason,
      null,
      $runId
    ]);

    // Werk de actuele MusicBrainz-workflowstatus van de artiest bij.
    $upsertState = $pdo->prepare("
      INSERT INTO artist_external_match_state (
        artist_id,
        musicbrainz_status,
        attempt_count,
        last_error,
        resolved_by,
        resolved_at
      )
      VALUES (?, ?, 1, NULL, ?, NOW())

      ON DUPLICATE KEY UPDATE
        musicbrainz_status = VALUES(musicbrainz_status),
        attempt_count = attempt_count + 1,
        last_error = NULL,
        resolved_by = VALUES(resolved_by),
        resolved_at = VALUES(resolved_at)
    ");

    $upsertState->execute([
      $artistId,
      $decision,
      'dry_run_musicbrainz'
    ]);

    echo "Beste kandidaat: "
      . htmlspecialchars($bestName)
      . "<br>";

    echo "MBID: "
      . htmlspecialchars($bestMbid)
      . "<br>";

    echo "Score #1: {$bestScore}<br>";

    echo "Score #2: "
      . (
        // Kijk of er een tweede kandidaat is om het scoreverschil te kunnen berekenen.
        $hasSecondCandidate
        ? $secondScore
        : 'geen kandidaat'
      )
      . "<br>";

    echo "Margin: "
      . (
        $margin !== null
        ? $margin
        : 'n.v.t.'
      )
      . "<br>";

    echo "Exact name: "
      . ($exactName ? 'JA' : 'NEE')
      . "<br>";

    echo "Alias match: "
      . ($aliasMatch ? 'JA' : 'NEE')
      . "<br>";

    echo "Composite: "
      . ($composite ? 'JA' : 'NEE')
      . "<br>";

    echo "Duplicate external ID: "
      . (
        $duplicateExternalId
        ? 'JA'
        : 'NEE'
      )
      . "<br>";

    echo "Bestaande Spotify-ID: "
      . htmlspecialchars(
        $existingSpotifyId
          ?? 'geen'
      )
      . "<br>";

    echo "Spotify relation match: "
      . (
        $spotifyRelationMatch
        ? 'JA'
        : 'NEE'
      )
      . "<br>";

    echo "Independent signal: "
      . (
        $independentSignal
        ? 'JA'
        : 'NEE'
      )
      . "<br>";

    echo "<strong>
            DRY-RUN BESLUIT:
            {$decision}
        </strong><br>";

    echo "Reden: "
      . htmlspecialchars($reason)
      . "<br>";
  // Vang API- of databasefouten op zodat één fout de hele run niet stopt.
  } catch (Exception $e) {

    $decision = 'retry';
    $metrics[$decision]++;

    // Sla een tijdelijke fout op als retry, zodat de artiest later opnieuw kan worden geprobeerd.
    $upsertRetryState = $pdo->prepare("
      INSERT INTO artist_external_match_state (
        artist_id,
        musicbrainz_status,
        attempt_count,
        last_error
      )
      VALUES (?, 'retry', 1, ?)

      ON DUPLICATE KEY UPDATE
        musicbrainz_status = 'retry',
        attempt_count = attempt_count + 1,
        last_error = VALUES(last_error)
    ");

    $upsertRetryState->execute([
      $artistId,
      $e->getMessage()
    ]);

    echo "
        <strong>
        DRY-RUN BESLUIT: retry
        </strong><br>
        ";

    echo "Tijdelijke fout: "
      . htmlspecialchars(
        $e->getMessage()
      )
      . "<br>";
  }

  echo "<hr>";
}


echo "<h2>Dry-run samenvatting</h2>";

echo "Matched: " . $metrics['matched'] . "<br>";
echo "Review: " . $metrics['review'] . "<br>";
echo "No match: " . $metrics['no_match'] . "<br>";
echo "Retry: " . $metrics['retry'] . "<br>";

echo "<strong>Totaal: "
  . array_sum($metrics)
  . "</strong>";
