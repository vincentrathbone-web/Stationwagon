<?php use function Stationwagon\h; use Stationwagon\App;
/** @var array $result @var string $station */ ?>
<h1><?= h($station) ?> is set up</h1>
<?php if ($result['config_written']): ?>
  <p class="lede">The database tables, your station and your admin account are ready, and the settings file is saved.</p>
  <p><a class="btn primary" href="<?= h(App::url('')) ?>">Go to the station</a></p>
<?php else: ?>
  <p class="lede">The database is ready, but the server didn't let the installer save its settings file.</p>
  <section class="card">
    <h2>One more step</h2>
    <p class="muted">Create a file called <code>config.local.php</code> next to <code>index.php</code> with exactly this content (cPanel → File Manager → + File), then open the station.</p>
    <pre class="code" id="cfg"><?= h($result['config']) ?></pre>
    <p class="muted">It holds your database password. Don't share it.</p>
    <p><a class="btn primary" href="<?= h(App::url('')) ?>">I've saved it, open the station</a></p>
  </section>
<?php endif ?>
