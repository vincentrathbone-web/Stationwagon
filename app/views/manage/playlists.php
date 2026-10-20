<?php use function Stationwagon\{h, duration}; use Stationwagon\{App, Playlists};
/** @var array $lists @var string $csrf */
?>
<div class="vhead">
  <div>
    <h1>Playlists</h1>
    <p>A playlist plays while a schedule block names it. Outside the schedule, the AutoDJ plays the whole library.</p>
  </div>
</div>

<div class="tablewrap">
  <table>
    <thead><tr><th>Name</th><th>Plays</th><th class="num">Songs</th><th class="num">Length</th><th class="num">On the schedule</th><th></th></tr></thead>
    <tbody>
    <?php if (!$lists): ?><tr><td colspan="6" class="muted2">No playlists yet. Make one below.</td></tr><?php endif ?>
    <?php foreach ($lists as $l): ?>
      <tr>
        <td><a href="<?= h(App::url('manage/playlists/' . (int) $l['id'])) ?>"><b><?= h($l['name']) ?></b></a></td>
        <td><span class="pill <?= $l['active'] ? 'auto' : '' ?>"><?= $l['active'] ? h(Playlists::MODES[$l['mode']]) : 'Switched off' ?></span></td>
        <td class="num"><?= (int) $l['songs'] ?></td>
        <td class="num"><?= h(duration((float) $l['seconds'])) ?></td>
        <td class="num"><?= (int) $l['blocks'] ? (int) $l['blocks'] . ' block' . ((int) $l['blocks'] === 1 ? '' : 's') : '—' ?></td>
        <td class="acts"><a class="btn sm" href="<?= h(App::url('manage/playlists/' . (int) $l['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<form class="card newform" method="post" action="<?= h(App::url('manage/playlists')) ?>">
  <h2>New playlist</h2>
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <div class="formrow">
    <label class="field"><span class="lbl">Name</span><input name="name" required placeholder="e.g. Morning worship"></label>
    <label class="field"><span class="lbl">Plays</span><select name="mode"><?php foreach (Playlists::MODES as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach ?></select></label>
    <button class="btn primary" type="submit">Make playlist</button>
  </div>
</form>
