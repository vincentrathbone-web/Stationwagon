<?php use function Stationwagon\h; use Stationwagon\App; use Stationwagon\Skins;
/**
 * The frame page (round 61): a manager screen, the studio or the account page opened in a browser loads
 * here, with the screen in a frame and the bar (`bar`) under it. Moving between screens changes only the
 * screen's frame, so the bar plays on with no gap; shell.js keeps the address bar and the title in step.
 * The apps never get this page: they have a bar area of their own (App::wantsShell).
 * @var string $screen the screen's address (path and query) @var string $title
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Stationwagon</title>
<?= Skins::headScript() ?>
<?= App::icons() ?>
<link rel="stylesheet" href="<?= h(App::asset('assets/app.css')) ?>">
</head>
<body class="shell" data-base="<?= h(App::url('')) ?>">
<iframe class="shell-screen" id="swScreen" name="swScreen" src="<?= h($screen) ?>" title="<?= h($title) ?>"
  allow="microphone; autoplay; screen-wake-lock; clipboard-read; clipboard-write; display-capture; fullscreen"></iframe>
<iframe class="shell-bar" id="swBar" src="<?= h(App::url('bar')) ?>" title="On air now" allow="autoplay"></iframe>
<script src="<?= h(App::asset('assets/shell.js')) ?>"></script>
</body>
</html>
