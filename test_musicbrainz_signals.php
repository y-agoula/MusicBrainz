<?php

/*
 * DETAILTEST VOOR MUSICBRAINZ-KANDIDATEN
 *
 * Toont voor een kleine set moeilijke artiesten de topkandidaten met
 * type, land, gebied, disambiguation en aliassen.
 */
require_once 'MusicBrainzClient.php';

// Maak één MusicBrainzClient aan voor alle API-aanroepen in dit script.
$client = new MusicBrainzClient();

$testArtists = [
  'P!nk',
  'Nova',
  'Artist A feat. Artist B'
];

// Doorloop alle testartiesten.
foreach ($testArtists as $artistName) {

  echo "<h2>" . htmlspecialchars($artistName) . "</h2>";

  try {
    // Vraag MusicBrainz om zoekkandidaten.
    $response = $client->searchArtist($artistName, 3);
    $candidates = $response['artists'] ?? [];

    // Als er geen kandidaten zijn, bepaal dan een veilige fallbackstatus.
    if (empty($candidates)) {
      echo "Geen kandidaten gevonden.<hr>";
      continue;
    }

    $position = 1;

    foreach ($candidates as $candidate) {

      $mbid = $candidate['id'] ?? null;

      if (!$mbid) {
        continue;
      }

      // Haal extra kandidaatdetails op, zoals aliassen en URL-relaties.
      $details = $client->getArtist($mbid);

      echo "<strong>Kandidaat {$position}</strong><br>";
      echo "Naam: " . htmlspecialchars($details['name'] ?? '') . "<br>";
      echo "MBID: " . htmlspecialchars($mbid) . "<br>";
      echo "Type: " . htmlspecialchars($details['type'] ?? '-') . "<br>";
      echo "Country: " . htmlspecialchars($details['country'] ?? '-') . "<br>";
      echo "Area: " . htmlspecialchars($details['area']['name'] ?? '-') . "<br>";
      echo "Disambiguation: "
        . htmlspecialchars($details['disambiguation'] ?? '-')
        . "<br>";

      echo "Aliases:<br>";

      $aliases = $details['aliases'] ?? [];

      if (empty($aliases)) {
        echo "- geen<br>";
      } else {
        foreach ($aliases as $alias) {
          echo "- "
            . htmlspecialchars($alias['name'] ?? '')
            . "<br>";
        }
      }

      echo "<br>";

      $position++;
    }
  // Vang API- of databasefouten op zodat één fout de hele run niet stopt.
  } catch (Exception $e) {
    echo "Fout: " . htmlspecialchars($e->getMessage());
  }

  echo "<hr>";
}
