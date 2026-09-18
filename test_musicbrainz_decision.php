<?php

/*
 * TEST VOOR BESLISLOGICA
 *
 * Draait de gelabelde testset door de voorlopige beslisregels en controleert
 * signalen zoals naam, alias, land, type, margin en duplicate-ID.
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

// Controleer of deze MBID al aan een andere Festivalinfo-artiest gekoppeld is.
function hasDuplicateExternalId(
  PDO $pdo,
  ?int $artistId,
  string $mbid
): bool {

  if ($artistId === null) {
    return false;
  }

  $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM platform_link
        WHERE entity_type_id = 1
          AND platform_id = 6
          AND external_identifier = ?
          AND entity_id <> ?
    ");

  $stmt->execute([
    $mbid,
    $artistId
  ]);

  return (int)$stmt->fetchColumn() > 0;
}

// Bouw veilige alternatieve zoekvarianten zonder betekenisvolle woorden blind te verwijderen.
function getSearchVariants(string $artistName): array
{
  $variants = [$artistName];

  // Alleen bekende redactionele suffixen veilig verwijderen.
  $cleaned = preg_replace(
    '/\s*\(([A-Z]{2})\)\s*$/u',
    '',
    $artistName
  );

  // Veilige variant voor apostrof/punctuatie.
  $cleaned = str_replace(
    ["'", "’"],
    '',
    $cleaned
  );

  $cleaned = preg_replace('/\s+/', ' ', trim($cleaned));

  if ($cleaned !== '' && $cleaned !== $artistName) {
    $variants[] = $cleaned;
  }

  return array_values(array_unique($variants));
}

// Normaliseer een artiestennaam zodat kleine leestekenverschillen niet meetellen.
function normalizeArtistName(string $name): string
{
  $name = mb_strtolower($name);

  $name = str_replace(
    ["'", "’", ".", ",", "-", "_"],
    "",
    $name
  );

  return trim($name);
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

// Maak één MusicBrainzClient aan voor alle API-aanroepen in dit script.
$client = new MusicBrainzClient();

// Voer de databasequery uit.
$stmt = $pdo->query("
    SELECT
        artist_id,
        artist_name,
        expected_country,
        expected_type
    FROM artist_match_testset
    ORDER BY test_id
");

// Zet de gevonden rijen om naar associatieve arrays.
$testArtists = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Doorloop alle testartiesten.
foreach ($testArtists as $test) {

  $artistId = $test['artist_id'];
  $artistName = $test['artist_name'];
  $expectedCountry = $test['expected_country'];
  $expectedType = $test['expected_type'];

  echo "<h2>" . htmlspecialchars($artistName) . "</h2>";

  $composite = isCompositeName($artistName);

  try {
    $candidates = [];
    $usedQuery = null;

    // Probeer de zoekvarianten op volgorde en stop zodra er resultaten zijn.
    foreach (getSearchVariants($artistName) as $queryVariant) {

      // Vraag MusicBrainz om zoekkandidaten.
      $response = $client->searchArtist($queryVariant, 3);
      $found = $response['artists'] ?? [];

      if (!empty($found)) {
        $candidates = $found;
        $usedQuery = $queryVariant;
        break;
      }
    }

    echo "Gebruikte query: "
      . htmlspecialchars($usedQuery ?? 'geen')
      . "<br>";

    // Als er geen kandidaten zijn, bepaal dan een veilige fallbackstatus.
    if (empty($candidates)) {

      $decision = $composite
        ? 'review'
        : 'no_match';

      echo "Geen kandidaten gevonden.<br>";
      echo "<strong>Besluit: {$decision}</strong><hr>";

      continue;
    }

    // Neem de hoogst gerangschikte MusicBrainz-kandidaat als kandidaat #1.
    $best = $candidates[0];

    $bestScore = (float)($best['score'] ?? 0);

    // Kijk of er een tweede kandidaat is om het scoreverschil te kunnen berekenen.
    $hasSecondCandidate = isset($candidates[1]);

    $secondScore = $hasSecondCandidate
      ? (float)($candidates[1]['score'] ?? 0)
      : null;

    // Bereken de afstand tussen kandidaat #1 en kandidaat #2.
    $margin = $hasSecondCandidate
      ? $bestScore - $secondScore
      : null;

    // Haal extra kandidaatdetails op, zoals aliassen en URL-relaties.
    $details = $client->getArtist($best['id']);

    // Voorkom dat dezelfde MBID stil aan twee Festivalinfo-artiesten wordt gekoppeld.
    $duplicateExternalId = hasDuplicateExternalId(
      $pdo,
      $artistId,
      $best['id']
    );

    $candidateCountry = $details['country'] ?? null;
    $candidateType = $details['type'] ?? null;

    $countryMatch =
      $expectedCountry !== null
      && $candidateCountry !== null
      && $expectedCountry === $candidateCountry;

    $typeMatch =
      $expectedType !== null
      && $candidateType !== null
      && $expectedType === $candidateType;

    // Verzamel het onafhankelijke bewijs dat nodig is voor een automatische match.
    $independentSignal =
      $countryMatch || $typeMatch;

    echo "Country match: "
      . ($countryMatch ? 'JA' : 'NEE')
      . "<br>";

    echo "Type match: "
      . ($typeMatch ? 'JA' : 'NEE')
      . "<br>";

    echo "Independent signal: "
      . ($independentSignal ? 'JA' : 'NEE')
      . "<br>";

    echo "Duplicate external ID: "
      . ($duplicateExternalId ? 'JA' : 'NEE')
      . "<br>";

    $bestName = $details['name'] ?? '';

    // Controleer of de genormaliseerde namen exact overeenkomen.
    $exactName =
      normalizeArtistName($artistName)
      === normalizeArtistName($bestName);

    // Start met de aanname dat er nog geen alias-match is gevonden.
    $aliasMatch = false;

    foreach ($details['aliases'] ?? [] as $alias) {

      if (
        normalizeArtistName($alias['name'] ?? '')
        === normalizeArtistName($artistName)
      ) {
        $aliasMatch = true;
        break;
      }
    }

    /*
         * Voorlopige regels.
         * Nog NIET als definitieve productie-threshold gebruiken.
         */
    if ($composite) {

      $decision = 'review';
    } elseif ($duplicateExternalId) {

      $decision = 'review';
    } elseif (
      ($exactName || $aliasMatch)
      && (
        !$hasSecondCandidate
        || $margin >= 15
      )
      && $independentSignal
    ) {

      $decision = 'matched';
    } else {

      $decision = 'review';
    }

    echo "Beste kandidaat: "
      . htmlspecialchars($bestName)
      . "<br>";

    echo "Score #1: {$bestScore}<br>";
    echo "Score #2: "
      . ($hasSecondCandidate ? $secondScore : 'geen kandidaat')
      . "<br>";

    echo "Margin: "
      . ($margin !== null ? $margin : 'n.v.t.')
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

    echo "<strong>Voorlopig besluit: {$decision}</strong>";
  // Vang API- of databasefouten op zodat één fout de hele run niet stopt.
  } catch (Exception $e) {

    echo "Tijdelijke fout → RETRY<br>";
    echo htmlspecialchars($e->getMessage());
  }

  echo "<hr>";
}
