<?php

/*
 * PROTOTYPE MUSICBRAINZ MATCHER
 *
 * Dit script verwerkt artiesten met status 'nieuw', zoekt kandidaten via
 * MusicBrainz, berekent een voorlopige matchscore en zet twijfelgevallen klaar
 * voor handmatige review.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Script gestart\n";

// Laad de gedeelde MusicBrainz-client.
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

/*
 * Alleen artiesten ophalen die nog niet gematcht zijn.
 */
$stmt = $pdo->query("
    SELECT artist_id, artist_name
    FROM musicbrainz
    where status = 'nieuw'
");

// Zet de gevonden rijen om naar associatieve arrays.
$artists = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<pre>';
print_r($artists);
echo '</pre>';

// Verwerk iedere geselecteerde artiest afzonderlijk.
foreach ($artists as $artist) {

  $artistId = $artist['artist_id'];
  $artistName = $artist['artist_name'];

  echo "Zoeken naar: {$artistName}\n";

  try {
    // Vraag MusicBrainz om zoekkandidaten.
    $result = $client->searchArtist($artistName, 5);

    $candidates = $result['artists'] ?? [];

    /*
         * Geen resultaten gevonden.
         */
    if (count($candidates) === 0) {

      $update = $pdo->prepare("
                UPDATE musicbrainz
                SET status = 'not_found'
                WHERE artist_id = ?
            ");

      $update->execute([$artistId]);

      echo "Geen MusicBrainz-resultaat gevonden.\n\n";

      // Wacht bewust om de publieke MusicBrainz-API niet te snel aan te spreken.
      sleep(2);
      continue;
    }

    /*
         * MusicBrainz sorteert de zoekresultaten al op relevantie.
         * Daarom nemen we voorlopig kandidaat 0 als beste kandidaat.
         */
    $bestCandidate = $candidates[0];

    $mbid = $bestCandidate['id'] ?? null;
    $score = (float) ($bestCandidate['score'] ?? 0);
    $matchedName = $bestCandidate['name'] ?? '';

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

    $normalizedOriginal = normalizeArtistName($artistName);
    $normalizedMatched = normalizeArtistName($matchedName);

    similar_text(
      $normalizedOriginal,
      $normalizedMatched,
      $nameSimilarity
    );

    // Start met de aanname dat er nog geen alias-match is gevonden.
    $aliasMatch = false;

    $aliases = $bestCandidate['aliases'] ?? [];

    foreach ($aliases as $alias) {

      $aliasName = $alias['name'] ?? '';

      if (
        normalizeArtistName($aliasName)
        === $normalizedOriginal
      ) {
        $aliasMatch = true;
        break;
      }
    }

    if ($aliasMatch) {
      $nameSimilarity = 100;
    }

    $finalScore =
      ($nameSimilarity * 0.70)
      + ($score * 0.30);

    $finalScore = round($finalScore, 2);

    if ($finalScore >= 90) {
      $status = 'matched';
    } else {
      $status = 'review';
    }

    if ($status === 'review') {

      // Oude kandidaten verwijderen om dubbele rijen te voorkomen
      $delete = $pdo->prepare("
        DELETE FROM musicbrainz_candidates
        WHERE artist_id = ?
    ");

      $delete->execute([$artistId]);

      // Alle gevonden MusicBrainz-kandidaten opslaan
      $insertCandidate = $pdo->prepare("
    INSERT INTO musicbrainz_candidates (
        artist_id,
        candidate_name,
        musicbrainz_mbid,
        candidate_match_score,
        candidate_type,
        candidate_country,
        candidate_area,
        candidate_disambiguation
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

      foreach ($candidates as $candidate) {

        $candidateName = $candidate['name'] ?? '';
        $candidateMbid = $candidate['id'] ?? null;
        $candidateScore = (float) ($candidate['score'] ?? 0);

        $candidateType = $candidate['type'] ?? null;
        $candidateCountry = $candidate['country'] ?? null;
        $candidateArea = $candidate['area']['name'] ?? null;
        $candidateDisambiguation = $candidate['disambiguation'] ?? null;

        if ($candidateMbid !== null) {
          $insertCandidate->execute([
            $artistId,
            $candidateName,
            $candidateMbid,
            $candidateScore,
            $candidateType,
            $candidateCountry,
            $candidateArea,
            $candidateDisambiguation
          ]);
        }
      }
    }

    $update = $pdo->prepare("
            UPDATE musicbrainz
            SET musicbrainz_mbid = ?,
                artist_match_score = ?,
                status = ?
            WHERE artist_id = ?
        ");

    $update->execute([
      $mbid,
      $finalScore,
      $status,
      $artistId
    ]);

    echo "Beste kandidaat: {$matchedName}\n";
    echo "MBID: {$mbid}\n";
    echo "MusicBrainz score: {$score}\n";
    echo "Eigen matchscore: {$finalScore}\n";
    echo "Status: {$status}\n\n";
  // Vang API- of databasefouten op zodat één fout de hele run niet stopt.
  } catch (Exception $e) {

    echo "Fout bij {$artistName}: ";
    echo $e->getMessage() . "\n\n";
  }

  /*
     * MusicBrainz publieke API niet te snel aanspreken.
     */
  sleep(2);
}
