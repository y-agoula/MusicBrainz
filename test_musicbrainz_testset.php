<?php

/*
 * TESTRUNNER VOOR DE GELABELDE TESTSET
 *
 * Zoekt per testartiest MusicBrainz-kandidaten en controleert of de
 * handmatig vastgelegde verwachte MBID tussen de resultaten staat.
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

// Voer de databasequery uit.
$stmt = $pdo->query("
    SELECT *
    FROM artist_match_testset
    WHERE artist_name IN (
        'P!nk',
        'Nova',
        'Artist A feat. Artist B'
    )
    ORDER BY test_id
");

// Zet de gevonden rijen om naar associatieve arrays.
$tests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Doorloop alle records uit de gelabelde testset.
foreach ($tests as $test) {

    echo "<h3>" . htmlspecialchars($test['artist_name']) . "</h3>";

    try {
        // Vraag MusicBrainz om zoekkandidaten.
        $response = $client->searchArtist($test['artist_name'], 10);
        $results = $response['artists'] ?? [];
    // Vang API- of databasefouten op zodat één fout de hele run niet stopt.
    } catch (Exception $e) {
        echo "⚠️ Tijdelijke MusicBrainz-fout: "
            . htmlspecialchars($e->getMessage())
            . "<hr>";

        // Wacht bewust om de publieke MusicBrainz-API niet te snel aan te spreken.
        sleep(3);
        continue;
    }

    // Als er geen kandidaten zijn, bepaal dan een veilige fallbackstatus.
    if (empty($results)) {
        echo "Geen kandidaten gevonden.<br>";
        echo "Verwacht: " . htmlspecialchars($test['expected_result']) . "<hr>";
        // Wacht bewust om de publieke MusicBrainz-API niet te snel aan te spreken.
        sleep(3);
        continue;
    }

    $expectedMbid = $test['expected_musicbrainz_mbid'];
    $foundExpected = false;

    $position = 1;

    foreach ($results as $candidate) {

        $mbid = $candidate['id'] ?? '';
        $name = $candidate['name'] ?? '';
        $score = $candidate['score'] ?? 0;

        if ($expectedMbid !== null && $mbid === $expectedMbid) {
            $foundExpected = true;
        }

        echo $position . ". ";
        $position++;
        echo htmlspecialchars($name);
        echo " | score: " . htmlspecialchars((string)$score);
        echo " | MBID: " . htmlspecialchars($mbid);

        if ($expectedMbid !== null && $mbid === $expectedMbid) {
            echo " ✅ EXPECTED";
        }

        echo "<br>";
    }

    if ($expectedMbid !== null) {
        echo "<strong>";
        echo $foundExpected
            ? "✅ Correcte artiest zit in de kandidaten"
            : "❌ Correcte artiest niet gevonden";
        echo "</strong><br>";
    }

    echo "Verwacht resultaat: " . htmlspecialchars($test['expected_result']) . "<hr>";

    // MusicBrainz rate limit respecteren
    sleep(2);
}
