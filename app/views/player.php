<?php use function Stationwagon\h; use Stationwagon\{App, Config};
/** @var ?array $station @var ?array $onAir @var array $today @var int $delay */
$name = $station['name'] ?? 'Stationwagon';
$tz = new DateTimeZone($station['timezone'] ?? 'UTC');
$day = (new DateTimeImmutable('now', $tz))->format('l j F');
$hm = fn(string $t): string => substr($t, 0, 5);
?>
<div class="lp" id="player" data-base="<?= h(App::url('')) ?>" data-delay="<?= (int) $delay ?>" data-station="<?= h($name) ?>" data-meter="<?= h(App::asset('assets/outwave-meter.js')) ?>">
  <div class="lp-stack">
    <section class="lp-win" aria-label="Player">
      <div class="lp-titlebar"><span><?= h($name) ?></span><span class="lp-lamp"><i></i><span id="lpMode">AutoDJ</span></span></div>
      <div class="lp-lcd">
        <span class="lp-time" id="lpTime">0:00</span>
        <div class="lp-marquee" aria-live="polite"><span id="lpTitle"><?= h($onAir['current']['title'] ?? 'Loading…') ?></span></div>
        <button class="lp-rights" id="lpRights" type="button" hidden aria-haspopup="dialog"></button>
        <canvas id="lpSpec" aria-hidden="true"></canvas>
        <div class="lp-mode"><span id="lpNext"></span><span id="lpBehind"><?= (int) $delay ?> s behind live</span></div>
      </div>
      <canvas class="lp-wave" id="lpWave" role="img" aria-label="What goes out: the sound scrolling past the white line (now), each song in its own colour, and the coming seconds dimmed on the right"></canvas>
      <div class="lp-seek" aria-hidden="true"><div class="lp-fill" id="lpFill"></div></div>
      <div class="lp-seekmeta"><span id="lpElapsed">0:00</span><span id="lpRemain"></span></div>
      <div class="lp-transport">
        <button class="btn primary lp-play" id="lpPlay" type="button" aria-pressed="false"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 2l10 6-10 6z"/></svg><span>Listen</span></button>
        <button class="btn" id="lpStop" type="button" disabled>Stop</button>
        <div class="lp-vol"><label for="lpVol">Volume</label><input id="lpVol" type="range" min="0" max="100" value="80"><output id="lpVolOut" for="lpVol">80</output></div>
      </div>
      <div class="lp-health"><span>Connection <b id="lpConn">Ready</b></span><span>Dropouts <b id="lpDrops">0</b></span><span>Listening now <b id="lpListeners">–</b></span></div>
      <p class="lp-note" id="lpNote" role="status" hidden></p>
    </section>

    <details class="lp-win lp-diag" id="lpDiag">
      <summary class="lp-titlebar"><span>Diagnostics</span><span class="lp-diag-sum" id="lpDiagSum"></span></summary>
      <div class="lp-diag-top">
        <p id="lpDiagVerdict">Press Listen to start measuring.</p>
        <span class="lp-diag-btns"><button class="btn sm" id="lpDiagSend" type="button">Send report</button><button class="btn sm" id="lpDiagCopy" type="button">Copy</button></span>
      </div>
      <dl class="lp-diag-rows" id="lpDiagRows"></dl>
      <h3 class="lp-diag-h">Dropouts <b id="lpDiagDropN">0</b></h3>
      <ol class="lp-diag-drops" id="lpDiagDrops"></ol>
      <p class="lp-diag-msg" id="lpDiagMsg" role="status"></p>
    </details>

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
    <section class="lp-notes" id="lpNotes" aria-labelledby="lpNotesTitle" hidden>
      <div class="lp-notes-head"><h2 id="lpNotesTitle">From the presenter</h2><span class="tag" id="lpNotesTag"></span></div>
      <p class="lp-notes-by" id="lpNotesBy"></p>
      <ol class="lp-notes-list" id="lpNotesList" aria-live="polite"></ol>
    </section>
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
<p class="lp-foot"><a href="<?= h(App::url('podcast')) ?>">Podcast: past shows</a> · Listening sets no cookies. The station counts its listeners for 30 days by internet address and browser: <a href="<?= h(App::url('privacy')) ?>">Privacy</a></p>
<div class="lp-toast" id="lpToast" role="status" hidden></div>
<dialog class="rightsdlg" id="lpRDlg" aria-labelledby="lpRTitle">
  <h2 id="lpRTitle">Copyright</h2>
  <p class="muted2" id="lpRSong"></p>
  <div id="lpRBody"></div>
  <div class="dlgbtns"><button class="btn primary" type="button" id="lpRClose">Close</button></div>
</dialog>
<script src="<?= h(App::asset('assets/fades.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/outwave.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/spectrum.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/listeners.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/rights.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/player.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/diag.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/notes.js')) ?>" defer></script>
