<?php use function Stationwagon\h;
/** @var ?array $onAir  from App::onAir() */
$clock = function (float $t) use ($onAir): string {
    return (new DateTimeImmutable('@' . (int) floor($t)))->setTimezone(new DateTimeZone($onAir['tz']))->format('H:i');
};
$label = fn(array $e): string => $e['type'] === 'live' ? 'LIVE · ' . $e['title'] : trim(($e['artist'] ? $e['artist'] . ' – ' : '') . $e['title']);
?>
<section class="card onair">
  <h2>On air</h2>
  <?php if ($onAir === null): ?>
    <p class="muted">The timeline can't be read right now.</p>
  <?php elseif (!$onAir['songs']): ?>
    <p class="muted">Nothing yet: the library is empty. Songs added to it start playing straight away.</p>
  <?php else: ?>
    <ul class="checks">
      <?php if ($onAir['current']): ?>
        <li class="ok"><span class="dot" aria-hidden="true"></span><span class="what"><b><?= h($label($onAir['current'])) ?></b><small>Now, since <?= h($clock($onAir['current']['start'])) ?></small></span><span class="found"><?= h($onAir['current']['type'] === 'live' ? 'live' : 'AutoDJ') ?></span></li>
      <?php endif ?>
      <?php if ($onAir['next']): ?>
        <li><span class="dot" aria-hidden="true" style="background:var(--faint)"></span><span class="what"><b><?= h($label($onAir['next'])) ?></b><small>Next, at <?= h($clock($onAir['next']['start'])) ?></small></span><span class="found">AutoDJ</span></li>
      <?php endif ?>
    </ul>
    <p class="muted" style="margin:12px 0 0"><?= (int) $onAir['songs'] ?> songs in the library. Listeners hear it about 20 seconds later.</p>
  <?php endif ?>
</section>
