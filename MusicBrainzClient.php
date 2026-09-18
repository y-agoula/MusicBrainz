<?php

/*
 * MUSICBRAINZ API CLIENT
 *
 * Verzorgt zoek- en detailrequests naar MusicBrainz WS/2.
 * Bevat rate limiting en retry-logica voor tijdelijke fouten.
 */
class MusicBrainzClient
{
    private string $baseUrl = 'https://musicbrainz.org/ws/2';
    private string $userAgent = 'Festivalinfo-MusicBrainz-Matcher/1.0 (contact@festivalinfo.nl)';
    private float $minInterval = 1.2;
    private float $lastRequestTime = 0.0;

    public function searchArtist(string $artistName, int $limit = 5): array
    {
        $query = urlencode('artist:"' . $artistName . '"');

        $url = $this->baseUrl
            . '/artist/?query=' . $query
            . '&fmt=json'
            . '&limit=' . $limit;

        return $this->request($url);
    }

    public function getArtist(string $mbid): array
    {
        $url = $this->baseUrl
            . '/artist/' . urlencode($mbid)
            . '?inc=aliases+url-rels'
            . '&fmt=json';

        return $this->request($url);
    }

    private function waitForRateLimit(): void
    {
        $now = microtime(true);

        if ($this->lastRequestTime > 0) {
            $elapsed = $now - $this->lastRequestTime;

            if ($elapsed < $this->minInterval) {
                $wait = $this->minInterval - $elapsed;
                usleep((int) ($wait * 1_000_000));
            }
        }

        $this->lastRequestTime = microtime(true);
    }

    // Voer de HTTP-request uit en handel tijdelijke API-fouten met retries af.
    private function request(string $url, int $maxAttempts = 3): array
    {
        $waitTimes = [5, 10, 20];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->waitForRateLimit();

            $ch = curl_init();

            // Stel de cURL-request in, inclusief timeout en herkenbare headers.
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: ' . $this->userAgent,
                    'Accept: application/json'
                ]
            ]);

            // Voer de HTTP-request uit.
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($response !== false && $httpCode === 200) {
                // Zet de JSON-response om naar een PHP-array.
                $data = json_decode($response, true);

                if (!is_array($data)) {
                    throw new Exception('Invalid JSON returned by MusicBrainz');
                }

                return $data;
            }

            // Beschouw netwerkfouten, HTTP 429 en 503 als tijdelijke fouten.
            $temporaryError =
                $response === false ||
                $httpCode === 429 ||
                $httpCode === 503;

            // Wacht en probeer opnieuw zolang er retry-pogingen over zijn.
            if ($temporaryError && $attempt < $maxAttempts) {
                // Wacht bewust om de publieke MusicBrainz-API niet te snel aan te spreken.
                sleep($waitTimes[$attempt - 1]);
                continue;
            }

            if ($response === false) {
                throw new Exception(
                    'MusicBrainz request failed: ' . $curlError
                );
            }

            throw new Exception(
                'MusicBrainz returned HTTP status ' . $httpCode
            );
        }

        throw new Exception('MusicBrainz request failed after retries');
    }
}
