<?php use function Stationwagon\{h, clock, duration, megabytes}; use Stationwagon\{App, Auth};
/** @var array $me @var array $station @var array $onAir @var float $now @var ?float $plannedUntil @var int $songs @var int $bytes @var int $playlists @var int $blocks @var ?array $block */
$tz = $station['timezone'];
$hour = (int) clock($now, $tz, 'G');
$hello = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$cur = $onAir['current'];
$next = $onAir['next'];
?>
<div class="vhead">
  <div>
    <h1><?= h($hello) ?>, <?= h(explode(' ', $me['name'])[0]) ?></h1>
    <p><?= h(\Stationwagon\Staff::ROLE_NAMES[$me['role']]) ?> · station clock <?= h(clock($now, $tz, 'H:i')) ?> (<?= h($tz) ?>)</p>
  </div>
</div>

<div class="cols">
  <section class="card">
    <h2>On air now</h2>
    <?php if (!$cur): ?>
      <p class="muted">Nothing is playing: <?= $songs ? 'the timeline is being planned.' : 'the library is empty. Add songs in Music library.' ?></p>
    <?php else: $pct = $cur['type'] === 'song' && $cur['play'] > 0 ? max(0, min(100, ($now - $cur['start']) / $cur['play'] * 100)) : 100; ?>
      <div class="lcdbox onair">
        <b class="ttl"><?= h($label($cur)) ?></b>
        <div class="prog" aria-hidden="true"><i style="width:<?= round($pct, 1) ?>%"></i></div>
        <div class="sub2"><span><?= h(duration($now - $cur['start'])) ?> of <?= $cur['type'] === 'song' ? h(duration($cur['play'])) : 'live' ?></span>
          <span><?= $cur['type'] === 'live' ? 'LIVE' : ($block ? 'AutoDJ · ' . h($block['title']) : 'AutoDJ · whole library') ?></span></div>
      </div>
      <?php if ($next): ?><p class="muted" style="margin-top:10px">Next at <?= h(clock($next['start'], $tz, 'H:i:s')) ?>: <b><?= h($label($next)) ?></b></p><?php endif ?>
      <?php if (Auth::allows($me, 'manager') && $cur['type'] === 'song'): ?>
        <div class="row-actions"><?= $post('manage/skip', 'Skip this song', [], 'btn') ?><span class="hint2">The song fades out over 2 s at the next piece boundary.</span></div>
      <?php endif ?>
    <?php endif ?>
  </section>

  <section class="card">
    <h2>Health</h2>
    <ul class="checks">
      <li class="<?= $plannedUntil && $plannedUntil > $now + 1800 ? 'ok' : 'warn' ?>"><span class="dot"></span><span class="what"><b>Timeline planned</b><small>until <?= $plannedUntil ? h(clock($plannedUntil, $tz, 'H:i')) : '—' ?></small></span><span class="found"><?= $plannedUntil ? h(duration($plannedUntil - $now)) : '0:00' ?> ahead</span></li>
      <li class="<?= $songs ? 'ok' : 'warn' ?>"><span class="dot"></span><span class="what"><b>Music library</b><small><?= h(megabytes($bytes)) ?> stored</small></span><span class="found"><?= $songs ?> songs</span></li>
      <li class="ok"><span class="dot"></span><span class="what"><b>Playlists and schedule</b><small><?= $blocks ? 'The schedule picks the playlist' : 'No schedule yet: AutoDJ plays the whole library' ?></small></span><span class="found"><?= $playlists ?> / <?= $blocks ?></span></li>
    </ul>
  </section>
</div>
