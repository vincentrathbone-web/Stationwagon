<?php use function Stationwagon\h; use Stationwagon\App;
/** @var array $result @var string $station @var string $jobs @var string $cron @var array $steps */ ?>
<h1><?= h($station) ?> is set up</h1>
<?php if ($steps): ?>
  <ul class="checks" style="margin-bottom:18px">
    <?php foreach ($steps as $s): ?><li class="ok"><span class="dot" aria-hidden="true"></span><span class="what"><b><?= h($s) ?></b></span><span class="found"></span></li><?php endforeach ?>
  </ul>
<?php endif ?>
<?php if ($result['config_written']): ?>
  <p class="lede">The database tables, your station and your admin account are ready, and the settings file is saved.</p>
  <?php if ($jobs === 'manual'): ?>
  <section class="card" style="margin-top:18px">
    <h2>Your cron job</h2>
    <p class="muted">The background jobs already run during visits. For exact timing, add this in cPanel → <b>Cron Jobs</b>, <b>Every 5 minutes</b>:</p>
    <pre class="code copyline"><?= h($cron) ?></pre>
  </section>
  <?php endif ?>
  <p style="margin-top:18px"><a class="btn primary" href="<?= h(App::url('')) ?>">Go to the station</a></p>
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
