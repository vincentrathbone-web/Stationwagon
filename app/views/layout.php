<?php use function Stationwagon\h; use Stationwagon\App; use Stationwagon\Config; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Stationwagon</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="<?= h(App::url('assets/app.css')) ?>?v=<?= h(Config::version()) ?>">
</head>
<body>
<header class="top"><span class="brand">stationwagon</span><span class="ver"><?= h(Config::version()) ?></span></header>
<main class="page"><?= $content ?></main>
</body>
</html>
