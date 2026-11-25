<?php use function Stationwagon\h; use Stationwagon\App;
/**
 * What aired in the last days (round 87): the Ads and Jingles screens, from PlayLog (every stream, kept after the timeline forgets).
 * @var array{days:list<string>,rows:list<array>} $plays @var string $playRoute @var string $playNoun ('ad' or 'jingle') @var bool $playAdvertiser
 */
$dayHead = fn(string $d) => (new DateTimeImmutable($d))->format('D j');
$dayTotals = [];
foreach ($plays['rows'] as $r) {
    foreach ($r['counts'] as $d => $n) {
        $dayTotals[$d] = ($dayTotals[$d] ?? 0) + $n;
    }
}
$csv = fn(int $days) => h(App::url($playRoute) . '?log=csv&days=' . $days);
?>
<section class="card playlog" id="playLog" aria-labelledby="playLogH">
  <div class="playloghead">
    <h2 id="playLogH">Played, last <?= count($plays['days']) ?> days</h2>
    <span class="playlogdl">Download the full log:
      <a class="btn sm" href="<?= $csv(30) ?>" download>30 days (CSV)</a>
      <a class="btn sm" href="<?= $csv(90) ?>" download>90 days</a>
      <a class="btn sm" href="<?= $csv(365) ?>" download>A year</a>
    </span>
  </div>
  <?php if (!$plays['rows']): ?>
    <p class="muted2" id="playLogEmpty">No <?= h($playNoun) ?> has played in the last <?= count($plays['days']) ?> days. Each play is logged here once it has finished, on every stream.</p>
  <?php else: ?>
    <div class="playscroll">
      <table class="playlogt" id="playLogTable">
        <thead><tr>
          <th><?= h(ucfirst($playNoun)) ?></th>
          <?php if ($playAdvertiser): ?><th>Advertiser</th><?php endif ?>
          <?php foreach ($plays['days'] as $d): ?><th class="num"><?= h($dayHead($d)) ?></th><?php endforeach ?>
          <th class="num">Total</th>
        </tr></thead>
        <tbody>
        <?php foreach ($plays['rows'] as $r): ?>
          <tr<?= $r['track_id'] === null ? ' class="off"' : '' ?>>
            <td><?= h($r['title']) ?></td>
            <?php if ($playAdvertiser): ?><td><?= h((string) ($r['advertiser'] ?? '')) ?></td><?php endif ?>
            <?php foreach ($plays['days'] as $d): ?><td class="num"><?= (int) ($r['counts'][$d] ?? 0) ?: '·' ?></td><?php endforeach ?>
            <td class="num"><b><?= (int) $r['total'] ?></b></td>
          </tr>
        <?php endforeach ?>
        </tbody>
        <tfoot><tr>
          <th scope="row"<?= $playAdvertiser ? ' colspan="2"' : '' ?>>Every <?= h($playNoun) ?></th>
          <?php foreach ($plays['days'] as $d): ?><td class="num"><?= (int) ($dayTotals[$d] ?? 0) ?></td><?php endforeach ?>
          <td class="num"><b><?= array_sum($dayTotals) ?></b></td>
        </tr></tfoot>
      </table>
    </div>
    <p class="muted2">Counted on every stream, in the station's time; a play is logged once it has finished. The CSV lists each play with its date, time and stream.</p>
  <?php endif ?>
</section>
