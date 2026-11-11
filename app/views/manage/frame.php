<?php use function Stationwagon\h; use Stationwagon\{App, Auth};
/** @var array $me @var array $station @var string $inner @var string $section @var ?array $nowPlaying @var string $csrf @var list<array> $streams @var array $stream */
$rail = [
    'overview' => ['manage', 'Overview', 'dj'],
    'library' => ['manage/library', 'Music library', 'library'],
    'ads' => ['manage/ads', 'Ads', 'library'],
    'jingles' => ['manage/jingles', 'Jingles', 'library'],
    'playlists' => ['manage/playlists', 'Playlists', 'playlists'],
    'schedule' => ['manage/schedule', 'Schedule', 'schedule'],
    'people' => ['manage/people', 'People', 'people'],
    'streams' => ['manage/streams', 'Streams', 'admin'],
    'roles' => ['manage/roles', 'Roles', 'admin'],
    'sound' => ['manage/sound', 'Sound & safety', 'sound'],
    'songprep' => ['manage/songprep', 'Song prep', 'library'],
    'listeners' => ['manage/listeners', 'Listeners', 'listeners'],
    'podcast' => ['manage/podcast', 'Podcast', 'dj'],
    'about' => ['manage/about', 'About', 'dj'], // last: the bottom of the rail (round 68)
];
// The Download button of a clip (round 71): the processed MP3 as it plays on air. (`manage/download?id=..&version=original` gives the file
// the clip was made from, when the station kept it; the Song prep screen and the Music library do not have a button for it.)
$mayDownload = Auth::allows($me, 'download');
$dl = function (int $id, string $what = 'clip') use ($mayDownload): string {
    return $mayDownload ? '<a class="btn sm" href="' . h(App::url('manage/download') . '?id=' . $id) . '" download title="Download this ' . h($what) . ' as it plays on air (the processed MP3)">Download</a>' : '';
};
// A small POST form around one button; $fields are hidden inputs
$post = function (string $route, string $label, array $fields = [], string $class = 'btn sm') use ($csrf): string {
    $hidden = '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
    foreach ($fields as $k => $v) {
        $hidden .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    return '<form method="post" action="' . h(App::url($route)) . '" class="inline">' . $hidden . '<button class="' . h($class) . '" type="submit">' . h($label) . '</button></form>';
};
$label = fn(array $e): string => $e['type'] === 'live' ? 'LIVE · ' . $e['title'] : (!empty($e['ad']) ? 'Ad · ' . $e['ad_title'] : (!empty($e['jingle']) ? 'Jingle · ' . $e['jingle_title'] : trim(($e['artist'] ? $e['artist'] . ' – ' : '') . $e['title'])));
?>
<div class="mgr">
  <div class="mhdr">
    <div class="mbrand"><?= h($station['name']) ?><small>Station manager</small></div>
    <?php if ($nowPlaying): ?>
      <div class="nowread" title="On air now"><span class="lamp<?= $nowPlaying['type'] === 'live' ? ' live' : '' ?>"></span><span><?= h($label($nowPlaying)) ?></span></div>
    <?php endif ?>
  </div>
  <nav class="rail" aria-label="Manager sections">
    <?php foreach ($rail as $key => [$route, $name, $role]): if (!Auth::allows($me, $role)) { continue; } ?>
      <a href="<?= h(App::url($route)) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= h($name) ?></a>
    <?php endforeach ?>
  </nav>
  <section class="mmain">
    <?php if (count($streams ?? []) > 1 && in_array($section, ['overview', 'schedule', 'listeners'], true)): // which stream this screen is about (round 73) ?>
      <nav class="streamswitch" aria-label="Stream">
        <span class="muted2">Stream</span>
        <?php foreach ($streams as $s): $here = (int) $s['id'] === (int) $stream['id']; ?>
          <a class="<?= $here ? 'on' : '' ?>" href="<?= h(App::url(['schedule' => 'manage/schedule', 'listeners' => 'manage/listeners'][$section] ?? 'manage') . '?stream=' . rawurlencode((string) $s['slug'])) ?>"<?= $here ? ' aria-current="true"' : '' ?>><?= h($s['name']) ?></a>
        <?php endforeach ?>
      </nav>
    <?php endif ?>
    <?php require __DIR__ . "/$inner.php" ?>
  </section>
</div>
