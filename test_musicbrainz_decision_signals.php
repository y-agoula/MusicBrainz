<?php

/*
 * TEST VOOR MATCHSIGNALEN
 *
 * Haalt MusicBrainz-kandidaten op en toont per kandidaat de belangrijkste
 * signalen die later gebruikt worden in de beslislogica.
 */
require_once 'MusicBrainzClient.php';

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

$testArtists = [
    'P!nk',
    'Nova',
    'Artist A feat. Artist B'
];

// Doorloop alle testartiesten.
foreach ($testArtists as $artistName) {

    echo "<h2>" . htmlspecialchars($artistName) . "</h2>";

    $compositeName = isCompositeName($artistName);

    echo "Composite name: "
        . ($compositeName ? "JA" : "NEE")
        . "<br><br>";

    try {
        // Vraag MusicBrainz om zoekkandidaten.
        $response = $client->searchArtist($artistName, 3);
        $candidates = $response['artists'] ?? [];

        // Als er geen kandidaten zijn, bepaal dan een veilige fallbackstatus.
        if (empty($candidates)) {
            echo "Geen kandidaten gevonden.<br>";

            if ($compositeName) {
                echo "<strong>Voorlopig resultaat: REVIEW</strong>";
            } else {
                echo "<strong>Voorlopig resultaat: NO_MATCH</strong>";
            }

            echo "<hr>";
            continue;
        }

        foreach ($candidates as $index => $candidate) {

            $mbid = $candidate['id'] ?? null;

            if (!$mbid) {
                continue;
            }

            // Haal extra kandidaatdetails op, zoals aliassen en URL-relaties.
            $details = $client->getArtist($mbid);

            $candidateName = $details['name'] ?? '';

            // Controleer of de genormaliseerde namen exact overeenkomen.
            $exactName =
                normalizeArtistName($candidateName)
                === normalizeArtistName($artistName);

            // Start met de aanname dat er nog geen alias-match is gevonden.
            $aliasMatch = false;

            foreach ($details['aliases'] ?? [] as $alias) {

                $aliasName = $alias['name'] ?? '';

                if (
                    normalizeArtistName($aliasName)
                    === normalizeArtistName($artistName)
                ) {
                    $aliasMatch = true;
                    break;
                }
            }

            echo "<strong>Kandidaat "
                . ($index + 1)
                . "</strong><br>";

            echo "Naam: "
                . htmlspecialchars($candidateName)
                . "<br>";

            echo "MBID: "
                . htmlspecialchars($mbid)
                . "<br>";

            echo "MusicBrainz score: "
                . htmlspecialchars((string)($candidate['score'] ?? 0))
                . "<br>";

            echo "Exact name: "
                . ($exactName ? "JA" : "NEE")
                . "<br>";

            echo "Alias match: "
                . ($aliasMatch ? "JA" : "NEE")
                . "<br>";

            echo "Type: "
                . htmlspecialchars($details['type'] ?? '-')
                . "<br>";

            echo "Country: "
                . htmlspecialchars($details['country'] ?? '-')
                . "<br>";

            echo "Disambiguation: "
                . htmlspecialchars($details['disambiguation'] ?? '-')
                . "<br><br>";
        }

    // Vang API- of databasefouten op zodat één fout de hele run niet stopt.
    } catch (Exception $e) {

        echo "Tijdelijke fout: "
            . htmlspecialchars($e->getMessage());
    }

    echo "<hr>";
}
