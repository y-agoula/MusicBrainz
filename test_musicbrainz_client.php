<?php

/*
 * EENVOUDIGE API-TEST
 *
 * Controleert of de MusicBrainzClient een zoekopdracht kan uitvoeren
 * en toont de ruwe API-response.
 */
require_once 'MusicBrainzClient.php';

// Maak één MusicBrainzClient aan voor alle API-aanroepen in dit script.
$client = new MusicBrainzClient();

try {
  // Vraag MusicBrainz om zoekkandidaten.
  $result = $client->searchArtist('Arctic Monkeys');

  print_r($result);
// Vang API- of databasefouten op zodat één fout de hele run niet stopt.
} catch (Exception $e) {
  echo 'Fout: ' . $e->getMessage();
}
