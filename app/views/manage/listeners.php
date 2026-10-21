<?php use function Stationwagon\h; use Stationwagon\{App, Geo, Listeners};
/** @var float $now @var int $listening @var array $hourly @var array $daily @var array $monthly @var array $breakdown
 *  @var ?string $geoVersion @var string $privacyEmail @var string $csrf */
$today = end($daily);
$thisMonth = end($monthly);
$hours = fn(int $minutes): string => $minutes >= 600 ? (string) round($minutes / 60) : rtrim(rtrim(number_format($minutes / 60, 1, '.', ''), '0'), '.');

/**
 * A column chart as inline SVG: one series, columns from a zero baseline with a rounded top, clean
 * y ticks, sparse x labels, and a hover target per column (the tooltip text in data-tip).
 * $rows: list of [label shown under the axis or '', value, tooltip text]
 */
$chart = function (array $rows, string $title) : string {
    $W = 720; $H = 210; $L = 34; $R = 6; $T = 10; $B = 26;
    $pw = $W - $L - $R; $ph = $H - $T - $B;
    $max = max(1, ...array_map(fn($r) => $r[1], $rows));
    // a clean top: 1, 2, 5 × 10^k, and whole-number ticks for small counts
    $mag = 10 ** floor(log10($max));
    $top = $mag * (($max / $mag) <= 1 ? 1 : (($max / $mag) <= 2 ? 2 : (($max / $mag) <= 5 ? 5 : 10)));
    $ticks = $top <= 4 ? range(0, (int) $top) : [0, $top / 2, $top];
    $y = fn(float $v): float => $T + $ph - $v / $top * $ph;
    $n = count($rows); $slot = $pw / $n; $bw = min(24, max(2, $slot - 2));
    $svg = '<svg class="lchart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . h($title) . '">';
    foreach ($ticks as $t) {
        $ty = round($y($t), 1);
        $svg .= '<line class="grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $ty . '" y2="' . $ty . '"/>'
              . '<text class="tick" x="' . ($L - 6) . '" y="' . ($ty + 4) . '" text-anchor="end">' . h(rtrim(rtrim(number_format($t, 1, '.', ''), '0'), '.')) . '</text>';
    }
    foreach ($rows as $i => [$label, $v, $tip]) {
        $x = $L + $i * $slot + ($slot - $bw) / 2;
        $svg .= '<g class="col" tabindex="0" data-tip="' . h($tip) . '"><rect class="hit" x="' . round($L + $i * $slot, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . $ph . '"/>';
        if ($v > 0) {
            $h = max(2.0, $v / $top * $ph); $top_ = $T + $ph - $h; $r = min(4.0, $bw / 2, $h);
            // square at the baseline, 4 px rounded at the data end
            $svg .= sprintf('<path class="bar" d="M%.1f %.1fV%.1fQ%.1f %.1f %.1f %.1fH%.1fQ%.1f %.1f %.1f %.1fV%.1fZ"/>',
                $x, $T + $ph, $top_ + $r, $x, $top_, $x + $r, $top_, $x + $bw - $r, $x + $bw, $top_, $x + $bw, $top_ + $r, $T + $ph);
        }
        $svg .= '</g>';
        if ($label !== '') {
            $svg .= '<text class="xlab" x="' . round($x + $bw / 2, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . h($label) . '</text>';
        }
    }
    $svg .= '<line class="axis" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . ($T + $ph) . '" y2="' . ($T + $ph) . '"/></svg>';
    return $svg;
};

$hourRows = array_map(function (array $r, int $i) use ($hourly) {
    // label 00:00, 06:00, 12:00 and 18:00; midnight by its day
    $hour = (int) substr($r['label'], 0, 2);
    $label = $r['label'] === '00:00' ? substr($r['day'], 0, 3) : ($hour % 6 === 0 && substr($r['label'], 3) === '00' ? $r['label'] : '');
    return [$label, $r['peak'], sprintf('%s %s · %d at once · %d min listened', $r['day'], $r['label'], $r['peak'], $r['minutes'])];
}, $hourly, array_keys($hourly));
$dayRows = array_map(function (array $r, int $i) use ($daily, $hours) {
    $label = ($i % 5 === 4 || $i === count($daily) - 1) ? preg_replace('/^\w+ /', '', $r['label']) : '';
    return [$label, round($r['minutes'] / 60, 2), sprintf('%s · %s h listened · %d at once at most · %d listens', $r['label'], $hours($r['minutes']), $r['peak'], $r['listens'])];
}, $daily, array_keys($daily));
$uniqueRows = array_map(function (array $r, int $i) use ($daily) {
    $label = ($i % 5 === 4 || $i === count($daily) - 1) ? preg_replace('/^\w+ /', '', $r['label']) : '';
    return [$label, $r['uniques'], sprintf('%s · %d unique listener%s', $r['label'], $r['uniques'], $r['uniques'] === 1 ? '' : 's')];
}, $daily, array_keys($daily));

/** One kind's list: the top few by hours listened, the rest as "Others". @return list<array{value:string, listens:int, minutes:int}> */
$top = function (array $rows, int $keep = 6): array {
    if (count($rows) <= $keep + 1) {
        return $rows;
    }
    $rest = array_slice($rows, $keep);
    return [...array_slice($rows, 0, $keep), ['value' => 'Others (' . count($rest) . ')', 'listens' => array_sum(array_column($rest, 'listens')), 'minutes' => array_sum(array_column($rest, 'minutes'))]];
};
$geoMonth = $geoVersion ? (new DateTimeImmutable($geoVersion . '-01'))->format('F Y') : null;
?>
<div class="vhead">
  <div>
    <h1>Listeners</h1>
    <p>Counted from open players: each one says once a minute that it's playing. Each Listen is kept for <?= Listeners::RAW_DAYS ?> days with the address and browser it came from, which tell the device, app and place; after that only the totals below are left. A unique listener is one address and browser in a day or a month.</p>
  </div>
</div>

<div class="mstats lstats">
  <div class="mstat"><b><?= (int) $listening ?></b><span>Listening now</span></div>
  <div class="mstat"><b><?= (int) $today['peak'] ?></b><span>Most at once today</span></div>
  <div class="mstat"><b><?= h($hours($today['minutes'])) ?></b><span>Hours listened today</span></div>
  <div class="mstat"><b><?= (int) $today['listens'] ?></b><span>Listens today</span></div>
  <div class="mstat"><b><?= (int) $today['uniques'] ?></b><span>Unique listeners today</span></div>
  <div class="mstat"><b><?= (int) $thisMonth['uniques'] ?></b><span>Unique listeners in <?= h(explode(' ', $thisMonth['label'])[0]) ?></span></div>
</div>

<section class="card lcard">
  <h2>The last 48 hours</h2>
  <p class="muted">The most listeners at once in each hour.</p>
  <div class="lwrap"><?= $chart($hourRows, 'The most listeners at once in each of the last 48 hours') ?></div>
  <details class="ltable">
    <summary>Show as a table</summary>
    <div class="tablewrap"><table>
      <thead><tr><th>Hour</th><th class="num">At once</th><th class="num">Minutes listened</th><th class="num">Listens</th></tr></thead>
      <tbody>
        <?php foreach (array_reverse($hourly) as $r): ?>
          <tr><td><?= h($r['day'] . ' ' . $r['label']) ?></td><td class="num"><?= (int) $r['peak'] ?></td><td class="num"><?= (int) $r['minutes'] ?></td><td class="num"><?= (int) $r['listens'] ?></td></tr>
        <?php endforeach ?>
      </tbody>
    </table></div>
  </details>
</section>

<section class="card lcard">
  <h2>The last 30 days</h2>
  <p class="muted">Hours listened each day, all listeners together.</p>
  <div class="lwrap"><?= $chart($dayRows, 'Hours listened on each of the last 30 days') ?></div>
  <details class="ltable">
    <summary>Show as a table</summary>
    <div class="tablewrap"><table>
      <thead><tr><th>Day</th><th class="num">Hours listened</th><th class="num">Unique listeners</th><th class="num">Most at once</th><th class="num">Listens</th></tr></thead>
      <tbody>
        <?php foreach (array_reverse($daily) as $r): ?>
          <tr><td><?= h($r['label']) ?></td><td class="num"><?= h($hours($r['minutes'])) ?></td><td class="num"><?= (int) $r['uniques'] ?></td><td class="num"><?= (int) $r['peak'] ?></td><td class="num"><?= (int) $r['listens'] ?></td></tr>
        <?php endforeach ?>
      </tbody>
    </table></div>
  </details>
</section>

<section class="card lcard">
  <h2>Unique listeners</h2>
  <p class="muted">Each day: one per address and browser, however often they pressed Listen. A family on one phone counts once; one person on a phone and a laptop counts twice.</p>
  <div class="lwrap"><?= $chart($uniqueRows, 'Unique listeners on each of the last 30 days') ?></div>
  <details class="ltable">
    <summary>By month</summary>
    <div class="tablewrap"><table>
      <thead><tr><th>Month</th><th class="num">Unique listeners</th><th class="num">Hours listened</th><th class="num">Most at once</th><th class="num">Listens</th></tr></thead>
      <tbody>
        <?php foreach (array_reverse($monthly) as $r): ?>
          <tr><td><?= h($r['label']) ?></td><td class="num"><?= (int) $r['uniques'] ?></td><td class="num"><?= h($hours($r['minutes'])) ?></td><td class="num"><?= (int) $r['peak'] ?></td><td class="num"><?= (int) $r['listens'] ?></td></tr>
        <?php endforeach ?>
      </tbody>
    </table></div>
  </details>
</section>

<section class="card lcard">
  <h2>Who listens, the last 30 days</h2>
  <p class="muted">Each one’s share of the hours listened.</p>
  <div class="lkinds">
    <?php foreach (Listeners::KINDS as $kind => $name):
        $rows = $top($breakdown[$kind]); $total = max(1, array_sum(array_column($breakdown[$kind], 'minutes'))); ?>
      <section class="lkind" id="lk-<?= h($kind) ?>">
        <h3><?= h($name) ?></h3>
        <?php if (!$rows): ?>
          <p class="muted">Nobody yet.</p>
        <?php else: ?>
          <ol>
            <?php foreach ($rows as $r): $pct = $r['minutes'] / $total * 100; ?>
              <li title="<?= h(sprintf('%s · %s h listened · %d listen%s', $r['value'], $hours($r['minutes']), $r['listens'], $r['listens'] === 1 ? '' : 's')) ?>">
                <span class="lname"><?= h($r['value']) ?></span>
                <span class="lpct"><?= $pct >= 1 || $pct == 0 ? (int) round($pct) : '&lt;1' ?> %</span>
                <span class="lbar" aria-hidden="true"><i style="width: <?= round($pct, 1) ?>%"></i></span>
              </li>
            <?php endforeach ?>
          </ol>
        <?php endif ?>
      </section>
    <?php endforeach ?>
  </div>
  <p class="muted lcredit">Countries and cities come from the address, looked up on this server in
    <a href="<?= h(Geo::CREDIT_URL) ?>" rel="noopener" target="_blank">IP Geolocation by DB-IP</a> (CC BY 4.0)<?= $geoMonth ? ', the ' . h($geoMonth) . ' edition, updated each month.' : '. <b>The location database isn’t here yet</b>: it is fetched within the day.' ?>
    Places are often only roughly right: a phone on mobile data can show a city far away.</p>
</section>

<section class="card lcard">
  <h2>Privacy</h2>
  <p class="muted">Listeners can read what is kept and why on the <a href="<?= h(App::url('privacy')) ?>">Privacy page</a>, linked under the player and in the embed. Give them an address to write to about it.</p>
  <form method="post" class="lprivacy">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label for="privacyEmail">Email for privacy questions</label>
    <div class="lrow">
      <input type="email" id="privacyEmail" name="privacy_email" value="<?= h($privacyEmail) ?>" maxlength="190" placeholder="privacy@yourchurch.org" autocomplete="email">
      <button class="btn">Save</button>
    </div>
    <?php if ($privacyEmail === ''): ?><p class="lwarn">Not set yet: the Privacy page asks listeners to contact the station instead.</p><?php endif ?>
  </form>
</section>
<p class="muted">Players on this site and on websites with the embed are counted. Apps that play the stream address directly can’t be (they never say they’re playing). Totals are kept for <?= (int) round(Listeners::KEEP_DAYS / 30) ?> months.</p>

<div class="ltip" id="lTip" role="tooltip" hidden></div>
<script>
(() => {
  // the tooltip for each column, on hover and on keyboard focus
  const tip = document.getElementById('lTip');
  // on a narrow screen the charts scroll: start at the newest end
  document.querySelectorAll('.lwrap').forEach((w) => { w.scrollLeft = w.scrollWidth; });
  const show = (g) => {
    tip.textContent = g.dataset.tip; tip.hidden = false;
    const b = g.getBoundingClientRect(), w = tip.offsetWidth;
    tip.style.left = Math.max(8, Math.min(innerWidth - w - 8, b.left + b.width / 2 - w / 2)) + 'px';
    tip.style.top = (b.top + scrollY - tip.offsetHeight - 6) + 'px';
  };
  document.querySelectorAll('.lchart .col').forEach((g) => {
    g.addEventListener('mouseenter', () => show(g)); g.addEventListener('focus', () => show(g));
    g.addEventListener('mouseleave', () => { tip.hidden = true; }); g.addEventListener('blur', () => { tip.hidden = true; });
  });
})();
</script>
