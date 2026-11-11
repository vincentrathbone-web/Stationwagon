<?php use function Stationwagon\h; use Stationwagon\App;
/**
 * What the Ads and the Jingles screens share with the Music library's look (round 72): the badges as symbols, the toolbar (search, status,
 * columns, compact rows), the bulk bar and the key. The page sets $clipRoute ('manage/ads'), $clipNoun ('ad'), $clipStore ('ads', where this
 * browser keeps the column choice), $cols (key => label of the columns that can be hidden), $hiddenCols, $q, $statusWords (the label of the
 * two states, for the filter and the bulk buttons: ['Running', 'Paused'] or ['On', 'Off']) and $bulkWords (['Pause', 'Resume'] or ['Turn off', 'Turn on']).
 * assets/cliplist.js does the sorting, the columns and the selection.
 * @var string $clipRoute @var string $clipNoun @var string $clipStore @var array $cols @var array $hiddenCols @var string $q @var array $statusWords @var array $bulkWords @var string $csrf
 */
$clipLufs = fn(float $v) => str_replace('-', '−', number_format($v, 1));
$clipSvg = [
    'mastered' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 2v12M8 2v12M13 2v12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="3" cy="10" r="2" fill="currentColor"/><circle cx="8" cy="5" r="2" fill="currentColor"/><circle cx="13" cy="9" r="2" fill="currentColor"/></svg>',
    'levelled' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 2v12M5 5l3-3 3 3M5 11l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
];
/** A clip's badges: mastered and levelled, as symbols with their words on hover (an ad or a jingle has no copyright badge). */
$clipBadges = function (array $r) use ($clipSvg, $clipLufs): string {
    $o = '';
    if ((int) ($r['mastered'] ?? 0)) {
        $o .= '<span class="pill auto sym mastered" role="img" title="Mastered" aria-label="Mastered">' . $clipSvg['mastered'] . '</span>';
    }
    if (($r['loudness_lufs'] ?? null) !== null) {
        $t = 'Levelled to ' . $clipLufs((float) $r['loudness_lufs']) . ' LUFS';
        $o .= ' <span class="pill auto sym levelled" role="img" title="' . h($t) . '" aria-label="' . h($t) . '">' . $clipSvg['levelled'] . '</span>';
    }
    return $o;
};
?>
<form class="toolbar" method="get" action="<?= h(App::url($clipRoute)) ?>" role="search">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search title or <?= $clipNoun === 'ad' ? 'advertiser' : 'notes' ?>" aria-label="Search the <?= h($clipNoun) ?>s">
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== ''): ?><a class="btn" href="<?= h(App::url($clipRoute)) ?>">Show all</a><?php endif ?>
  <select id="clipFilter" aria-label="Show <?= h($clipNoun) ?>s by state" hidden>
    <option value="">All states</option>
    <option value="on"><?= h($statusWords[0]) ?></option>
    <option value="off"><?= h($statusWords[1]) ?> or ended</option>
  </select>
  <details class="colmenu" id="colMenu" data-store="<?= h($clipStore) ?>" hidden>
    <summary class="btn">Columns</summary>
    <div class="colpanel" role="group" aria-label="Columns to show">
      <?php foreach ($cols as $key => $label): ?><label class="swrow"><input class="sw" type="checkbox" data-col="<?= h($key) ?>"<?= in_array($key, $hiddenCols, true) ? '' : ' checked' ?>> <?= h($label) ?></label><?php endforeach ?>
      <label class="swrow dense"><input class="sw" type="checkbox" id="denseToggle"> Compact rows</label>
    </div>
  </details>
</form>
<form class="bulkbar" id="bulkBar" method="post" action="<?= h(App::url($clipRoute)) ?>" hidden>
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="bulk"><input type="hidden" name="q" value="<?= h($q) ?>">
  <b id="bulkCount">0 selected</b>
  <span id="bulkIds"></span>
  <button class="btn sm" type="submit" name="value" value="off"><?= h($bulkWords[0]) ?></button>
  <button class="btn sm" type="submit" name="value" value="on"><?= h($bulkWords[1]) ?></button>
  <button class="btn sm" type="button" id="bulkNone">Deselect</button>
</form>
<p class="muted2 libnote" id="clipNote" role="status" aria-live="polite"></p>
<p class="badgekey muted2" id="badgeKey">Badges: <span class="pill auto sym" aria-hidden="true"><?= $clipSvg['mastered'] ?></span> mastered · <span class="pill auto sym" aria-hidden="true"><?= $clipSvg['levelled'] ?></span> levelled. Hover a badge for the details.</p>
