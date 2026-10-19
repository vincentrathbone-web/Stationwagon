<?php use function Stationwagon\h; use Stationwagon\App; ?>
<h1>Already installed</h1>
<p class="lede">Stationwagon is set up here, so the installer is switched off. To start again, delete <code>config.local.php</code> and the database tables first.</p>
<p><a class="btn primary" href="<?= h(App::url('')) ?>">Open the station</a></p>
