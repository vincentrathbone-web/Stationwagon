<?php use function Stationwagon\{h, clock}; use Stationwagon\{App, Podcast};
/** @var array $details @var array $episodes @var Podcast $podcast @var ?array $station */
$len = function (float $s): string { $s = (int) round($s); return $s >= 3600 ? sprintf('%d h %02d min', intdiv($s, 3600), intdiv($s % 3600, 60)) : sprintf('%d min', max(1, (int) round($s / 60))); };
$tz = $station['timezone'] ?? 'UTC';
?>
<article class="pod">
  <header class="podhead">
    <?php if ($details['cover']): ?><img src="<?= h(App::url($details['cover'])) ?>" alt="" width="132" height="132"><?php endif ?>
    <div>
      <h1><?= h($details['title']) ?></h1>
      <p class="lede"><?= nl2br(h($details['description'])) ?></p>
      <p class="podlinks"><a href="<?= h(App::url('podcast/feed')) ?>" id="podFeed">Podcast feed (RSS)</a> · copy it into any podcast app. <a href="<?= h(App::url('')) ?>">Listen live</a></p>
    </div>
  </header>
  <?php if (!$episodes): ?>
    <p class="muted" id="podEmpty">No episodes yet.</p>
  <?php endif ?>
  <ol class="podlist">
    <?php foreach ($episodes as $ep): $url = $podcast->publicUrl($ep); if ($url === null) { continue; } ?>
      <li class="card" data-episode="<?= h($ep['public_id']) ?>">
        <h2><?= h($ep['title']) ?></h2>
        <p class="podmeta"><?= h(clock((float) $ep['recorded_at'], $tz, 'j F Y')) ?> · <?= h($len((float) $ep['audio_seconds'])) ?></p>
        <?php if ((string) $ep['description'] !== ''): ?><p><?= nl2br(h((string) $ep['description'])) ?></p><?php endif ?>
        <audio controls preload="none" src="<?= h(App::url($url)) ?>"></audio>
      </li>
    <?php endforeach ?>
  </ol>
</article>
