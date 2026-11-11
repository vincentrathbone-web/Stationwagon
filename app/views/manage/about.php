<?php use function Stationwagon\h; use Stationwagon\App;
/** @var string $version */
$repo = 'https://github.com/vincentrathbone-web/Stationwagon';
?>
<div class="vhead">
  <div>
    <h1>About</h1>
    <p>Stationwagon is radio software for small stations that runs on ordinary shared hosting.</p>
  </div>
</div>

<div class="about">
  <section class="card">
    <h2>Versions</h2>
    <dl class="aboutdl">
      <dt>Station software</dt><dd id="aboutStation"><?= h($version) ?></dd>
      <dt id="aboutAppLabel" hidden>Windows app</dt><dd id="aboutAppVer" hidden></dd>
    </dl>
    <div class="aboutupd" id="aboutUpd" hidden>
      <button class="btn" type="button" id="aboutCheck">Check for updates</button>
      <p class="muted2" id="aboutMsg" role="status" aria-live="polite"></p>
      <div id="aboutFound" hidden>
        <h3>What’s new in <span id="aboutNew"></span></h3>
        <pre class="aboutnotes" id="aboutNotes"></pre>
        <button class="btn primary" type="button" id="aboutInstall">Update now</button>
      </div>
    </div>
    <p class="muted2" id="aboutBrowser">Updates for the Windows app are checked from the app itself: open this page in the Windows app and a <b>Check for updates</b> button appears here.</p>
  </section>

  <section class="card">
    <h2>The software</h2>
    <p>Stationwagon gives a small radio station an <b>AutoDJ with real crossfades</b>, a stream for listeners and a <b>studio for live shows</b> in the browser, on Windows or on Android. It needs no root access, no Docker, no extra ports and no programs running all day.</p>
    <ul class="aboutlist">
      <li>A <b>timeline</b> in the database plans which song plays when, hours ahead, from playlists and a weekly schedule.</li>
      <li>Listeners get <b>HLS</b>: a tiny playlist plus short pieces served by the web server. The player follows the same timeline 20 seconds behind the server clock, so every device hears the same moment.</li>
      <li>The manager has a music library, ads, playlists, a schedule, people and roles, sound and safety settings, song prep, listener statistics and a podcast.</li>
      <li>It runs on shared <b>cPanel</b> hosting (or a VPS) with <b>PHP 8.1+</b> and <b>MySQL or MariaDB</b>. A WordPress plugin installs and updates a station next to a WordPress site.</li>
    </ul>
  </section>

  <section class="card">
    <h2>Source and downloads</h2>
    <p><a href="<?= h($repo) ?>" target="_blank" rel="noopener">github.com/vincentrathbone-web/Stationwagon</a><br>
      The code, the guides, the full documentation and the releases of the Windows and Android apps.</p>
    <p><a href="<?= h($repo) ?>/releases" target="_blank" rel="noopener">Releases</a> · <a href="<?= h($repo) ?>/issues" target="_blank" rel="noopener">Report a problem</a></p>
  </section>
</div>
<script src="<?= h(App::asset('assets/about.js')) ?>" defer></script>
