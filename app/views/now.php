<?php use function Stationwagon\h; use Stationwagon\App; use Stationwagon\Skins;
/**
 * The small Now playing card other websites frame (round 61): album art, the song and artist, a thin
 * progress line and a link to where people listen. No sound. `?link=` (an http(s) address) is where it
 * leads (the station page by default), `?target=new` opens that in a new tab (else the whole page goes
 * there), `?look=light` suits a light website, `?look=ocean` Gansbaai's (round 79).
 * @var ?array $station @var string $look @var string $link @var bool $newTab
 */
$name = $station['name'] ?? 'Stationwagon';
?>
<!doctype html>
<html lang="en"<?= $look !== '' ? ' class="' . h($look) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($name) ?> · Now playing</title>
<?= Skins::headScript() ?>
<?= App::icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&family=Barlow:wght@400;500;600&family=Barlow+Semi+Condensed:wght@500;600;700&family=Share+Tech+Mono&family=Sora:wght@300;400;600;700&display=swap">
<link rel="stylesheet" href="<?= h(App::asset('assets/app.css')) ?>">
</head>
<body class="embed nowcard">
<div class="nw" id="now" data-base="<?= h(App::url('')) ?>"<?= ($streamSlug ?? '') !== '' ? ' data-stream="' . h($streamSlug) . '"' : '' ?>>
  <a class="nw-link" id="nwLink" href="<?= h($link) ?>" target="<?= $newTab ? '_blank' : '_top' ?>"<?= $newTab ? ' rel="noopener"' : '' ?>>
    <span class="nw-art" id="nwArt" aria-hidden="true">
      <img id="nwArtImg" alt="" hidden>
      <svg viewBox="0 0 120 120">
        <rect class="cd-case" x="3" y="3" width="114" height="114" rx="5"/>
        <rect class="cd-hinge" x="3" y="3" width="12" height="114" rx="3"/>
        <circle class="cd-disc" cx="67" cy="60" r="43"/>
        <circle class="cd-track" cx="67" cy="60" r="30"/>
        <circle class="cd-hub" cx="67" cy="60" r="13"/>
        <circle class="cd-hole" cx="67" cy="60" r="5"/>
      </svg>
    </span>
    <span class="nw-body">
      <span class="nw-top"><span class="nw-lamp"><i></i><span id="nwMode">Now playing</span></span><span class="nw-name"><?= h($name) ?></span></span>
      <strong class="nw-title" id="nwTitle" aria-live="polite">Loading…</strong>
      <span class="nw-artist" id="nwArtist"></span>
      <span class="nw-bar" aria-hidden="true"><span id="nwFill"></span></span>
    </span>
    <span class="nw-go">Listen <span aria-hidden="true">▸</span></span>
  </a>
</div>
<script src="<?= h(App::asset('assets/skin.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/now.js')) ?>" defer></script>
</body>
</html>
