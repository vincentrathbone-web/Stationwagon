<?php use function Stationwagon\h; use Stationwagon\App; use Stationwagon\Skins;
/** @var ?array $station @var bool $light: the light look for websites with a light background (?look=light) */
$name = $station['name'] ?? 'Stationwagon';
?>
<!doctype html>
<html lang="en"<?= $light ? ' class="light"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($name) ?> · Listen live</title>
<?= Skins::headScript() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&family=Barlow:wght@400;500;600&family=Barlow+Semi+Condensed:wght@500;600;700&family=Share+Tech+Mono&display=swap">
<link rel="stylesheet" href="<?= h(App::asset('assets/app.css')) ?>">
</head>
<body class="embed">
<div class="em" id="embed" data-base="<?= h(App::url('')) ?>" data-meter="<?= h(App::asset('assets/outwave-meter.js')) ?>">
  <?php /* shown when the frame is tall enough (280 px and up, app.css): the album art, the copyright line, the spectrum and the output strip */ ?>
  <div class="em-art" id="emArt" role="img" aria-label="Album art (a CD in its case when the song has none)">
    <img id="emArtImg" alt="" hidden>
    <svg viewBox="0 0 120 120" aria-hidden="true">
      <rect class="cd-case" x="3" y="3" width="114" height="114" rx="5"/>
      <rect class="cd-hinge" x="3" y="3" width="12" height="114" rx="3"/>
      <line class="cd-groove" x1="9" y1="10" x2="9" y2="110"/>
      <circle class="cd-disc" cx="67" cy="60" r="43"/>
      <circle class="cd-track" cx="67" cy="60" r="34"/>
      <circle class="cd-track" cx="67" cy="60" r="25"/>
      <circle class="cd-hub" cx="67" cy="60" r="13"/>
      <circle class="cd-hole" cx="67" cy="60" r="5"/>
      <path class="cd-shine" d="M15 3 H62 L15 62 Z"/>
    </svg>
  </div>
  <button class="em-play" id="emPlay" type="button" aria-pressed="false" aria-label="Listen">
    <svg class="i-play" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 2l10 6-10 6z"/></svg>
    <svg class="i-stop" viewBox="0 0 16 16" aria-hidden="true"><rect x="3" y="3" width="10" height="10" rx="1.5"/></svg>
  </button>
  <div class="em-body">
    <p class="em-top"><span class="em-lamp" id="emLamp"><i></i><span id="emMode">AutoDJ</span></span><a class="em-name" href="<?= h(App::url('')) ?>" target="_blank" rel="noopener"><?= h($name) ?></a></p>
    <p class="em-title" id="emTitle" aria-live="polite">Loading…</p>
    <button class="em-rights" id="emRights" type="button" hidden aria-haspopup="dialog"></button>
    <canvas class="em-spec" id="emSpec" aria-hidden="true"></canvas>
  </div>
  <canvas class="em-wave" id="emWave" role="img" aria-label="What you hear: the sound scrolling past the white line (now), the coming seconds dimmed on the right"></canvas>
  <div class="em-prog">
    <div class="em-bar" aria-hidden="true"><div id="emFill"></div></div>
    <p class="em-meta"><span id="emState">Press play to listen</span><span id="emNext"></span></p>
  </div>
  <div class="em-rdlg" id="emRDlg" role="dialog" aria-label="Copyright details" hidden>
    <p class="em-rsong" id="emRSong"></p>
    <div id="emRBody"></div>
    <button class="btn sm" type="button" id="emRClose">Close</button>
  </div>
  <div class="em-side">
    <a class="em-open" href="<?= h(App::url('')) ?>" target="_blank" rel="noopener">Open the player <span aria-hidden="true">↗</span></a>
    <span class="em-foot"><?= Skins::picker(true) ?><a class="em-priv" href="<?= h(App::url('privacy')) ?>" target="_blank" rel="noopener">Privacy</a></span>
  </div>
</div>
<script src="<?= h(App::asset('assets/skin.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/listeners.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/outwave.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/spectrum.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/rights.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/embed.js')) ?>" defer></script>
</body>
</html>
