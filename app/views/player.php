<?php use function Stationwagon\h; use Stationwagon\{App, Config};
/** @var ?array $station @var ?array $onAir @var array $today @var int $delay */
$name = $station['name'] ?? 'Stationwagon';
$tz = new DateTimeZone($station['timezone'] ?? 'UTC');
$day = (new DateTimeImmutable('now', $tz))->format('l j F');
$hm = fn(string $t): string => substr($t, 0, 5);
?>
<div class="lp" id="player" data-base="<?= h(App::url('')) ?>" data-delay="<?= (int) $delay ?>" data-station="<?= h($name) ?>">
  <div class="lp-stack">
    <section class="lp-win" aria-label="Player">
      <div class="lp-titlebar"><span><?= h($name) ?></span><span class="lp-lamp"><i></i><span id="lpMode">AutoDJ</span></span></div>
      <div class="lp-lcd">
        <span class="lp-time" id="lpTime">0:00</span>
        <div class="lp-marquee" aria-live="polite"><span id="lpTitle"><?= h($onAir['current']['title'] ?? 'Loading…') ?></span></div>
        <canvas id="lpSpec" aria-hidden="true"></canvas>
        <div class="lp-mode"><span id="lpNext"></span><span id="lpBehind"><?= (int) $delay ?> s behind live</span></div>
      </div>
      <div class="lp-seek" aria-hidden="true"><div class="lp-fill" id="lpFill"></div></div>
      <div class="lp-seekmeta"><span id="lpElapsed">0:00</span><span id="lpRemain"></span></div>
      <div class="lp-transport">
        <button class="btn primary lp-play" id="lpPlay" type="button" aria-pressed="false"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 2l10 6-10 6z"/></svg><span>Listen</span></button>
        <button class="btn" id="lpStop" type="button" disabled>Stop</button>
        <div class="lp-vol"><label for="lpVol">Volume</label><input id="lpVol" type="range" min="0" max="100" value="80"><output id="lpVolOut" for="lpVol">80</output></div>
      </div>
      <div class="lp-health"><span>Connection <b id="lpConn">Ready</b></span><span>Dropouts <b id="lpDrops">0</b></span></div>
      <p class="lp-note" id="lpNote" role="status" hidden></p>
    </section>

    <section class="lp-win" aria-label="Playlist">
      <div class="lp-titlebar"><span>Playlist</span></div>
      <ol class="lp-list" id="lpList"></ol>
    </section>
  </div>

  <aside class="lp-side">
    <header>
      <p class="lp-eyebrow">Listen live</p>
      <h1><?= h($name) ?></h1>
      <p class="lede">Press Listen to join everyone else at the same moment in the broadcast. The player stays <?= (int) $delay ?> seconds behind live, so every listener hears the same thing.</p>
    </header>
    <section>
      <h2>Today · <?= h($day) ?></h2>
      <?php if (!$today): ?>
        <p class="muted">Nothing is scheduled today: the AutoDJ plays the whole library.</p>
      <?php else: ?>
        <ol class="lp-sched">
          <?php foreach ($today as $b): $live = $b['kind'] === 'live'; ?>
            <li class="<?= $b['on_now'] ? 'current' : '' ?>">
              <time><?= h($b['start_time'] === $b['end_time'] ? 'All day' : $hm($b['start_time']) . '–' . $hm($b['end_time'])) ?></time>
              <div class="what"><b><?= h($b['title']) ?></b><span><?= h($live ? ($b['dj_name'] ? 'Live with ' . $b['dj_name'] : 'Live show') : 'AutoDJ' . ($b['playlist_name'] ? ' · ' . $b['playlist_name'] : '')) ?></span></div>
              <span class="tag<?= $b['on_now'] ? ' now' : ($live ? ' live' : '') ?>"><?= $b['on_now'] ? 'On now' : ($live ? 'Live' : 'AutoDJ') ?></span>
            </li>
          <?php endforeach ?>
        </ol>
      <?php endif ?>
    </section>
    <noscript><p class="muted">The player needs JavaScript. Any HLS player can play the stream at <code><?= h(App::url('api/stream.php')) ?></code>.</p></noscript>
  </aside>
</div>
<script src="<?= h(App::url('assets/player.js')) ?>?v=<?= h(Config::version()) ?>" defer></script>
