<?php use function Stationwagon\h; use Stationwagon\{App, Auth};
/** @var array $me @var array $station @var string $inner @var string $section @var ?array $nowPlaying @var string $csrf */
$rail = [
    'overview' => ['manage', 'Overview', 'dj'],
    'library' => ['manage/library', 'Music library', 'manager'],
    'playlists' => ['manage/playlists', 'Playlists', 'manager'],
    'schedule' => ['manage/schedule', 'Schedule', 'manager'],
    'djs' => ['manage/djs', 'DJs', 'manager'],
    'sound' => ['manage/sound', 'Sound & safety', 'manager'],
    'listeners' => ['manage/listeners', 'Listeners', 'manager'],
    'podcast' => ['manage/podcast', 'Podcast', 'dj'],
];
// A small POST form around one button; $fields are hidden inputs
$post = function (string $route, string $label, array $fields = [], string $class = 'btn sm') use ($csrf): string {
    $hidden = '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
    foreach ($fields as $k => $v) {
        $hidden .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    return '<form method="post" action="' . h(App::url($route)) . '" class="inline">' . $hidden . '<button class="' . h($class) . '" type="submit">' . h($label) . '</button></form>';
};
$label = fn(array $e): string => $e['type'] === 'live' ? 'LIVE · ' . $e['title'] : trim(($e['artist'] ? $e['artist'] . ' – ' : '') . $e['title']);
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
    <?php require __DIR__ . "/$inner.php" ?>
  </section>
</div>
