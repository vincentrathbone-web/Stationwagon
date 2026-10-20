<?php use function Stationwagon\h; use Stationwagon\{App, Schedule};
/** @var array $blocks @var array $playlists @var array $djs @var array $form @var int $today @var array $station @var string $csrf */
$in = $form['in'];
$err = $form['errors'];
$pieces = Schedule::gridPieces($blocks);
$hm = fn(string $t) => substr($t, 0, 5);
$chosenDays = array_map('intval', (array) ($in['days'] ?? array_keys(Schedule::DAYS)));
?>
<div class="vhead">
  <div>
    <h1>Weekly schedule</h1>
    <p>Times are the station clock (<?= h($station['timezone']) ?>). Where no block covers the time, the AutoDJ plays the whole library. Where blocks overlap, the newest wins.</p>
  </div>
</div>

<div class="tablewrap">
  <div class="week" style="--rows:18">
    <div></div>
    <?php foreach (array_values(Schedule::DAYS) as $i => $d): ?><div class="dh<?= $i === $today ? ' today' : '' ?>" style="grid-column:<?= $i + 2 ?>;grid-row:1"><?= h($d) ?></div><?php endforeach ?>
    <?php for ($hr = 0; $hr < 18; $hr++): ?>
      <div class="hr" style="grid-column:1;grid-row:<?= $hr + 2 ?>"><?= sprintf('%02d', $hr + 6) ?></div>
      <?php for ($d = 0; $d < 7; $d++): ?><div class="cell" style="grid-column:<?= $d + 2 ?>;grid-row:<?= $hr + 2 ?>"></div><?php endfor ?>
    <?php endfor ?>
    <?php foreach ($pieces as $p): $b = $p['block']; ?>
      <div class="blk <?= $b['kind'] === 'live' ? 'live' : 'auto' ?>" style="grid-column:<?= $p['day'] + 2 ?>;grid-row:2;margin-top:calc(<?= round($p['top'], 3) ?> * 28px);height:calc(<?= round($p['len'], 3) ?> * 28px - 2px)" title="<?= h($b['title'] . ' · ' . $hm($b['start_time']) . '–' . $hm($b['end_time'])) ?>">
        <?= h($b['title']) ?><span><?= h($hm($b['start_time']) . '–' . $hm($b['end_time'])) ?></span>
      </div>
    <?php endforeach ?>
  </div>
</div>
<div class="legend"><span><i class="auto"></i>AutoDJ playlist</span><span><i class="live"></i>Live show</span></div>

<div class="tablewrap">
  <table>
    <thead><tr><th>Block</th><th>Days</th><th>Time</th><th>Plays</th><th></th></tr></thead>
    <tbody>
    <?php if (!$blocks): ?><tr><td colspan="5" class="muted2">Nothing scheduled yet.</td></tr><?php endif ?>
    <?php foreach ($blocks as $b): ?>
      <tr>
        <td><b><?= h($b['title']) ?></b></td>
        <td><?= h(Schedule::dayNames((int) $b['days'])) ?></td>
        <td class="num" style="text-align:left"><?= h($hm($b['start_time']) . '–' . $hm($b['end_time'])) ?><?= $b['start_time'] === $b['end_time'] ? ' <span class="sub">all day</span>' : ($b['end_time'] < $b['start_time'] ? ' <span class="sub">past midnight</span>' : '') ?></td>
        <td><?= $b['kind'] === 'live' ? '<span class="pill live">Live</span> ' . h($b['dj_name'] ?? 'Any DJ') : '<span class="pill auto">AutoDJ</span> ' . h($b['playlist_name'] ?? '') ?></td>
        <td class="acts"><?= $post('manage/schedule', 'Remove', ['action' => 'delete', 'id' => (int) $b['id']], 'btn sm') ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<form class="card newform" method="post" action="<?= h(App::url('manage/schedule')) ?>">
  <h2>Add a block</h2>
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="add">
  <div class="formrow">
    <label class="field<?= isset($err['kind']) ? ' bad' : '' ?>"><span class="lbl">What</span>
      <select name="kind" id="kind">
        <option value="playlist"<?= ($in['kind'] ?? 'playlist') === 'playlist' ? ' selected' : '' ?>>AutoDJ plays a playlist</option>
        <option value="live"<?= ($in['kind'] ?? '') === 'live' ? ' selected' : '' ?>>Live show</option>
      </select></label>
    <label class="field<?= isset($err['playlist_id']) ? ' bad' : '' ?>" data-kind="playlist"><span class="lbl">Playlist</span>
      <select name="playlist_id"><?php if (!$playlists): ?><option value="">Make a playlist first</option><?php endif ?>
        <?php foreach ($playlists as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) ($in['playlist_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach ?></select></label>
    <label class="field<?= isset($err['dj_user_id']) ? ' bad' : '' ?>" data-kind="live"><span class="lbl">DJ</span>
      <select name="dj_user_id"><option value="">Any DJ</option>
        <?php foreach ($djs as $d): ?><option value="<?= (int) $d['id'] ?>"<?= (int) ($in['dj_user_id'] ?? 0) === (int) $d['id'] ? ' selected' : '' ?>><?= h($d['name']) ?></option><?php endforeach ?></select></label>
    <label class="field<?= isset($err['title']) ? ' bad' : '' ?>"><span class="lbl">Name</span><input name="title" value="<?= h($in['title'] ?? '') ?>" placeholder="Optional for playlists"></label>
  </div>
  <fieldset class="days<?= isset($err['days']) ? ' bad' : '' ?>"><legend class="lbl">Days</legend>
    <?php foreach (Schedule::DAYS as $bit => $d): ?><label><input type="checkbox" name="days[]" value="<?= $bit ?>"<?= in_array($bit, $chosenDays, true) ? ' checked' : '' ?>> <?= h($d) ?></label><?php endforeach ?>
  </fieldset>
  <div class="formrow">
    <label class="field<?= isset($err['start']) ? ' bad' : '' ?>"><span class="lbl">From</span><input type="time" name="start" value="<?= h($in['start'] ?? '06:00') ?>" required></label>
    <label class="field<?= isset($err['end']) ? ' bad' : '' ?>"><span class="lbl">Until</span><input type="time" name="end" value="<?= h($in['end'] ?? '09:00') ?>" required><span class="hint">Earlier than From = runs past midnight; the same = all day</span></label>
    <button class="btn primary" type="submit">Add block</button>
  </div>
</form>
<script>
// Show only the fields for the kind of block chosen
(() => {
  const kind = document.getElementById("kind");
  const sync = () => document.querySelectorAll("[data-kind]").forEach((el) => { el.hidden = el.dataset.kind !== kind.value; });
  kind.addEventListener("change", sync);
  sync();
})();
</script>
