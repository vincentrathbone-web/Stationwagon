<?php use function Stationwagon\h; use Stationwagon\Auth;
/** @var array $me @var ?array $station */
$tiles = [
    ['Library', 'Upload songs and jingles', 'manager'],
    ['Playlists', 'What the AutoDJ plays', 'manager'],
    ['Schedule', 'Playlists and live shows by the hour', 'manager'],
    ['DJs', 'Who can go live, and when', 'manager'],
    ['Studio', 'Go live from the browser', 'dj'],
];
?>
<span class="role"><?= h($me['role']) ?></span>
<h1>Hello, <?= h($me['name']) ?></h1>
<p class="lede">You’re managing <b><?= h($station['name'] ?? '') ?></b>. The station clock is <?= h($station['timezone'] ?? '') ?>.</p>
<div class="tiles">
  <?php foreach ($tiles as [$name, $what, $role]): if (!Auth::allows($me, $role)) { continue; } ?>
    <div class="tile"><b><?= h($name) ?></b><span><?= h($what) ?></span><span class="soon">Next in the build</span></div>
  <?php endforeach ?>
</div>
