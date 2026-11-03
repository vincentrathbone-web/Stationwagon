<?php use function Stationwagon\h; use Stationwagon\App; use Stationwagon\Config; use Stationwagon\Skins; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Stationwagon</title>
<?= Skins::headScript() ?>
<?= App::icons() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&family=Barlow:wght@400;500;600&family=Barlow+Semi+Condensed:wght@500;600;700&family=Share+Tech+Mono&display=swap">
<link rel="stylesheet" href="<?= h(App::asset('assets/app.css')) ?>">
<script src="<?= h(App::asset('assets/skin.js')) ?>" defer></script>
</head>
<body>
<header class="top<?= !empty($wide) ? ' wide' : '' ?>">
  <a class="brand" href="<?= h(App::url('')) ?>" aria-label="Stationwagon home"><?= App::logo() ?></a><span class="ver"><?= h(Config::version()) ?></span>
  <nav class="who">
    <?= Skins::picker() ?>
    <?php if (!empty($me)): ?>
      <a href="<?= h(App::url('studio')) ?>">Studio</a>
      <a href="<?= h(App::url('manage')) ?>">Manage</a>
      <a href="<?= h(App::url('account')) ?>" title="Your account"><?= h($me['name']) ?></a>
      <form method="post" action="<?= h(App::url('signout')) ?>"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="link" type="submit">Sign out</button></form>
    <?php elseif (Config::isInstalled() && ($title ?? '') !== 'Sign in'): ?>
      <a href="<?= h(App::url('signin')) ?>">Sign in</a>
    <?php endif ?>
  </nav>
</header>
<main class="page<?= !empty($wide) ? ' wide' : '' ?>">
<?php foreach ($flash as [$kind, $message]): ?><p class="alert <?= $kind === 'ok' ? 'ok' : 'bad' ?> toast" role="<?= $kind === 'ok' ? 'status' : 'alert' ?>"><?= h($message) ?></p><?php endforeach ?>
<?= $content ?></main>
</body>
</html>
