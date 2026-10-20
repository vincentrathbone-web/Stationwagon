<?php use function Stationwagon\{h, duration}; use Stationwagon\{App, Playlists};
/** @var array $list @var array $songs @var array $others @var bool $confirm @var string $csrf */
$id = (int) $list['id'];
$route = "manage/playlists/$id";
$total = array_sum(array_map(fn($s) => (float) $s['duration_s'], $songs));
?>
<div class="vhead">
  <div>
    <p class="crumb"><a href="<?= h(App::url('manage/playlists')) ?>">Playlists</a> /</p>
    <h1><?= h($list['name']) ?></h1>
    <p><?= count($songs) ?> songs · <?= h(duration($total)) ?> · <?= $list['active'] ? h(Playlists::MODES[$list['mode']]) : 'switched off (the AutoDJ plays the whole library instead)' ?></p>
  </div>
</div>

<form class="card" method="post" action="<?= h(App::url($route)) ?>">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save">
  <div class="formrow">
    <label class="field"><span class="lbl">Name</span><input name="name" value="<?= h($list['name']) ?>" required></label>
    <label class="field"><span class="lbl">Plays</span><select name="mode"><?php foreach (Playlists::MODES as $k => $v): ?><option value="<?= h($k) ?>"<?= $list['mode'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach ?></select></label>
    <label class="swrow"><input class="sw" type="checkbox" name="active" value="1"<?= $list['active'] ? ' checked' : '' ?>> On</label>
    <button class="btn primary" type="submit">Save</button>
  </div>
</form>

<div class="tablewrap">
  <table>
    <thead><tr><th class="num">#</th><th>Song</th><th class="num">Length</th><th></th></tr></thead>
    <tbody>
    <?php if (!$songs): ?><tr><td colspan="4" class="muted2">No songs yet. Add some from the library below.</td></tr><?php endif ?>
    <?php foreach ($songs as $i => $s): $tid = (int) $s['id']; ?>
      <tr>
        <td class="num"><?= $i + 1 ?></td>
        <td><b><?= h($s['title']) ?></b><?php if ($s['artist']): ?><span class="sub"><?= h($s['artist']) ?></span><?php endif ?></td>
        <td class="num"><?= h(duration((float) $s['duration_s'])) ?></td>
        <td class="acts">
          <?php if ($list['mode'] === 'in_order'): ?>
            <?= $i > 0 ? $post($route, '↑', ['action' => 'up', 'track' => $tid], 'btn sm') : '' ?>
            <?= $i < count($songs) - 1 ? $post($route, '↓', ['action' => 'down', 'track' => $tid], 'btn sm') : '' ?>
          <?php endif ?>
          <?= $post($route, 'Take out', ['action' => 'remove', 'track' => $tid], 'btn sm') ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<?php if ($others): ?>
<form class="card" method="post" action="<?= h(App::url($route)) ?>">
  <h2>Add from the library</h2>
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="add">
  <div class="picklist">
    <?php foreach ($others as $t): ?>
      <label><input type="checkbox" name="tracks[]" value="<?= (int) $t['id'] ?>"> <span><b><?= h($t['title']) ?></b><?= $t['artist'] ? ' · ' . h($t['artist']) : '' ?></span><span class="num"><?= h(duration((float) $t['duration_s'])) ?></span></label>
    <?php endforeach ?>
  </div>
  <button class="btn primary" type="submit">Add ticked songs</button>
</form>
<?php endif ?>

<section class="card danger-zone">
  <h2>Delete playlist</h2>
  <?php if ($confirm): ?>
    <p class="muted">Delete “<?= h($list['name']) ?>” and the schedule blocks that use it? The songs stay in the library.</p>
    <div class="row-actions"><?= $post($route, 'Yes, delete it', ['action' => 'delete'], 'btn danger') ?><a class="btn" href="<?= h(App::url($route)) ?>">Keep it</a></div>
  <?php else: ?>
    <a class="btn" href="<?= h(App::url($route) . '?confirm=1') ?>">Delete this playlist</a>
  <?php endif ?>
</section>
