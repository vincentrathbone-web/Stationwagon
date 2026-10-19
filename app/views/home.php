<?php use function Stationwagon\h;
/** @var ?array $station */ ?>
<h1><?= h($station['name'] ?? 'Stationwagon') ?></h1>
<p class="lede">The listener player comes later in the build. For now the stream plays in any HLS player at <code>api/stream.php</code>; station staff can sign in to the manager.</p>
<?php require __DIR__ . '/_onair.php' ?>
<section class="card">
  <ul class="checks">
    <li class="ok"><span class="dot" aria-hidden="true"></span><span class="what"><b>Database</b></span><span class="found">Connected</span></li>
    <li class="ok"><span class="dot" aria-hidden="true"></span><span class="what"><b>Station clock</b></span><span class="found"><?= h($station['timezone'] ?? '') ?></span></li>
  </ul>
</section>
