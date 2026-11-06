<?php
// The podcast for apps: the published episodes (title, date, length, audio address), or with
// ?episode=<id> one episode with the presenter's notes and slides placed in its audio (`at_s`: the
// second to show each at). Addresses are absolute. Anyone may read it.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
use Stationwagon\{Config, Podcast, PodcastPage};

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
if (!Config::isInstalled()) {
    http_response_code(503);
    echo json_encode(['error' => 'Not set up yet.']);
    exit;
}
try {
    $podcast = Podcast::forFirstStation();
    $base = PodcastPage::base();
    $id = (string) ($_GET['episode'] ?? '');
    if ($id !== '') {
        $episode = preg_match('/^ep-[0-9]{8}-[0-9a-f]{8}$/', $id) === 1 ? $podcast->publicEpisodeWithNotes($id, $base) : null;
        if ($episode === null) {
            http_response_code(404);
            echo json_encode(['error' => 'No such episode.']);
            exit;
        }
        echo json_encode(['episode' => $episode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode($podcast->publicList($base), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    error_log('Stationwagon podcast api: ' . $ex);
    http_response_code(503);
    echo json_encode(['error' => 'The station is busy. Try again.']);
}
