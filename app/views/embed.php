<?php use function Stationwagon\h; use Stationwagon\App;
/** @var ?array $station @var bool $light: the light look for websites with a light background (?look=light) */
$name = $station['name'] ?? 'Stationwagon';
?>
<!doctype html>
<html lang="en"<?= $light ? ' class="light"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($name) ?> · Listen live</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= h(App::asset('assets/app.css')) ?>">
</head>
<body class="embed">
<div class="em" id="embed" data-base="<?= h(App::url('')) ?>">
  <button class="em-play" id="emPlay" type="button" aria-pressed="false" aria-label="Listen">
    <svg class="i-play" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 2l10 6-10 6z"/></svg>
    <svg class="i-stop" viewBox="0 0 16 16" aria-hidden="true"><rect x="3" y="3" width="10" height="10" rx="1.5"/></svg>
  </button>
  <div class="em-body">
    <p class="em-top"><span class="em-lamp" id="emLamp"><i></i><span id="emMode">AutoDJ</span></span><a class="em-name" href="<?= h(App::url('')) ?>" target="_blank" rel="noopener"><?= h($name) ?></a></p>
    <p class="em-title" id="emTitle" aria-live="polite">Loading…</p>
    <div class="em-bar" aria-hidden="true"><div id="emFill"></div></div>
    <p class="em-meta"><span id="emState">Press play to listen</span><span id="emNext"></span></p>
  </div>
  <a class="em-open" href="<?= h(App::url('')) ?>" target="_blank" rel="noopener">Open the player <span aria-hidden="true">↗</span></a>
</div>
<script src="<?= h(App::asset('assets/embed.js')) ?>" defer></script>
</body>
</html>
