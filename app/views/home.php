<?php use function Stationwagon\h;
/** @var ?array $station */ ?>
<h1><?= h($station['name'] ?? 'Stationwagon') ?></h1>
<p class="lede">Installed and connected to its database. The listener player comes later in the build; station staff can sign in to the manager now.</p>
<section class="card">
  <ul class="checks">
    <li class="ok"><span class="dot" aria-hidden="true"></span><span class="what"><b>Database</b></span><span class="found">Connected</span></li>
    <li class="ok"><span class="dot" aria-hidden="true"></span><span class="what"><b>Station clock</b></span><span class="found"><?= h($station['timezone'] ?? '') ?></span></li>
  </ul>
</section>
