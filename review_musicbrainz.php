<?php

/*
 * HANDMATIGE MUSICBRAINZ REVIEW
 *
 * Toont artiesten met status 'review' en laat een gebruiker een kandidaat
 * bevestigen of aangeven dat geen van de kandidaten correct is.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Maak verbinding met de lokale Festivalinfo-testdatabase.
$pdo = new PDO(
  'mysql:host=localhost;dbname=festivalinfo-test;charset=utf8mb4',
  'root',
  ''
);

// Laat PDO databasefouten als exceptions gooien.
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*
 * Als het formulier is verstuurd
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $artistId = $_POST['artist_id'] ?? null;
  $candidateId = $_POST['candidate_id'] ?? null;

  if ($artistId && $candidateId) {

    // De reviewer geeft aan dat geen van de kandidaten correct is.
    if ($candidateId === 'none') {

      $update = $pdo->prepare("
        UPDATE musicbrainz
        SET
            musicbrainz_mbid = NULL,
            artist_match_score = NULL,
            status = 'not_found'
        WHERE artist_id = ?
    ");

      $update->execute([$artistId]);

      $deleteCandidates = $pdo->prepare("
        DELETE FROM musicbrainz_candidates
        WHERE artist_id = ?
    ");

      $deleteCandidates->execute([$artistId]);

      echo "<p>Geen juiste MusicBrainz-match gevonden.</p>";
    } else {

      /*
       * Geselecteerde kandidaat ophalen
       */
      $stmt = $pdo->prepare("
        SELECT
          candidate_name,
          musicbrainz_mbid,
          candidate_match_score
        FROM musicbrainz_candidates
        WHERE candidate_id = ?
          AND artist_id = ?
      ");

      $stmt->execute([
        $candidateId,
        $artistId
      ]);

      $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($candidate) {

        /*
         * Juiste MusicBrainz-match opslaan
         */
        $update = $pdo->prepare("
          UPDATE musicbrainz
          SET
            musicbrainz_mbid = ?,
            artist_match_score = ?,
            status = 'matched'
          WHERE artist_id = ?
        ");

        $update->execute([
          $candidate['musicbrainz_mbid'],
          $candidate['candidate_match_score'],
          $artistId
        ]);

        $deleteCandidates = $pdo->prepare("
    DELETE FROM musicbrainz_candidates
    WHERE artist_id = ?
");

        $deleteCandidates->execute([$artistId]);

        echo "<p>Match opgeslagen: "
          . htmlspecialchars($candidate['candidate_name'])
          . "</p>";
      }
    }
  }
}

/*
 * Alle artiesten ophalen die handmatig gecontroleerd moeten worden
 */
$stmt = $pdo->query("
    SELECT artist_id, artist_name
    FROM musicbrainz
    WHERE status = 'review'
    ORDER BY artist_name
");

// Zet de gevonden rijen om naar associatieve arrays.
$reviewArtists = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="nl">

<head>
  <meta charset="UTF-8">
  <title>MusicBrainz Review</title>
</head>

<body>

  <h1>MusicBrainz review</h1>

  <?php if (count($reviewArtists) === 0): ?>

    <p>Geen twijfelgevallen.</p>

  <?php endif; ?>

  <?php foreach ($reviewArtists as $artist): ?>

    <h2>
      <?= htmlspecialchars($artist['artist_name']) ?>
    </h2>

    <?php

    $candidateStmt = $pdo->prepare("
        SELECT
    candidate_id,
    candidate_name,
    musicbrainz_mbid,
    candidate_match_score,
    candidate_type,
    candidate_country,
    candidate_area,
    candidate_disambiguation
        FROM musicbrainz_candidates
        WHERE artist_id = ?
        ORDER BY candidate_match_score DESC
    ");

    $candidateStmt->execute([
      $artist['artist_id']
    ]);

    // Zet de gevonden rijen om naar associatieve arrays.
    $candidates = $candidateStmt->fetchAll(PDO::FETCH_ASSOC);

    ?>

    <form method="post">

      <input
        type="hidden"
        name="artist_id"
        value="<?= $artist['artist_id'] ?>">

      <?php foreach ($candidates as $candidate): ?>

        <label>
          <input
            type="radio"
            name="candidate_id"
            value="<?= $candidate['candidate_id'] ?>"
            required>

          <?= htmlspecialchars($candidate['candidate_name']) ?>

          -
          score:
          <?= htmlspecialchars($candidate['candidate_match_score']) ?>

          <?php if ($candidate['candidate_type']): ?>
            | type: <?= htmlspecialchars($candidate['candidate_type']) ?>
          <?php endif; ?>

          <?php if ($candidate['candidate_country']): ?>
            | country: <?= htmlspecialchars($candidate['candidate_country']) ?>
          <?php endif; ?>

          <?php if ($candidate['candidate_area']): ?>
            | area: <?= htmlspecialchars($candidate['candidate_area']) ?>
          <?php endif; ?>

          <?php if ($candidate['candidate_disambiguation']): ?>
            | <?= htmlspecialchars($candidate['candidate_disambiguation']) ?>
          <?php endif; ?>

        </label>

        <br>

      <?php endforeach; ?>

      <br>

      <label>
        <input
          type="radio"
          name="candidate_id"
          value="none"
          required>
        Geen van deze
      </label>

      <br><br>

      <button type="submit">
        Bevestigen
      </button>

    </form>

    <hr>

  <?php endforeach; ?>

</body>

</html>
