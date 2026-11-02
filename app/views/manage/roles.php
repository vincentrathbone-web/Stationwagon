<?php use function Stationwagon\h; use Stationwagon\{App, Permissions};
/** @var array $me @var array $roles @var string $open @var array $station @var string $csrf */
$url = App::url('manage/roles');
$hidden = fn(string $action, string $key = '') => '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="' . h($action) . '">' . ($key !== '' ? '<input type="hidden" name="key" value="' . h($key) . '">' : '');
$ticks = function (array $have, bool $locked): string {
    $out = '<div class="perms">';
    foreach (Permissions::ALL as $k => [$label, $what]) {
        $out .= '<label class="perm' . ($locked ? ' off' : '') . '"><input type="checkbox" name="perm[]" value="' . h($k) . '"' . (in_array($k, $have, true) ? ' checked' : '') . ($locked ? ' disabled' : '') . '>'
            . '<span><b>' . h($label) . '</b><small>' . h($what) . '</small></span></label>';
    }
    return $out . '</div>';
};
?>
<div class="vhead">
  <div>
    <h1>Roles</h1>
    <p>What each role may do. Everyone gets exactly what their role allows, so a change here applies to all its people at once. Everyone can go live in their own scheduled blocks, keep their running order and edit their own podcast episodes; the ticks add the rest. Admin always has everything. Give people their role under <a href="<?= h(App::url('manage/people')) ?>">People</a>.</p>
  </div>
</div>

<div class="tablewrap">
  <table class="people roles">
    <thead><tr><th>Role</th><th>May do</th><th>People</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($roles as $r): $k = $r['key']; $isOpen = $open === $k && $k !== 'admin'; ?>
      <tr data-role="<?= h($k) ?>">
        <td><b><?= h($r['name']) ?></b><?= $r['built_in'] ? '' : ' <span class="pill">Your own</span>' ?></td>
        <td class="cando"><?= h(Permissions::summary($r['permissions'])) ?></td>
        <td><?= $r['people'] ?></td>
        <td class="acts"><?php if ($k !== 'admin'): ?><a class="btn sm" href="<?= h($url . ($isOpen ? '' : '?role=' . rawurlencode($k))) ?>"><?= $isOpen ? 'Close' : 'Change' ?></a><?php endif ?></td>
      </tr>
      <?php if ($isOpen): ?>
        <tr class="editing"><td colspan="4">
          <div class="personform">
            <form method="post" action="<?= h($url) ?>" class="permform">
              <?= $hidden('save', $k) ?>
              <label class="field"><span class="lbl">Name</span><input name="name" value="<?= h($r['name']) ?>" maxlength="60" required></label>
              <span class="lbl">What <?= h($r['name']) ?> may do</span>
              <?= $ticks($r['permissions'], false) ?>
              <button class="btn sm" type="submit">Save</button>
            </form>
            <?php if (!$r['built_in']): ?>
              <form method="post" action="<?= h($url) ?>" class="formrow">
                <?= $hidden('delete', $k) ?>
                <?php if ($r['people'] > 0): ?>
                  <p class="hint grow">To delete this role, give its <?= $r['people'] ?> <?= $r['people'] === 1 ? 'person' : 'people' ?> another role first.</p>
                <?php else: ?>
                  <label class="swrow"><input type="checkbox" required> Yes, delete <?= h($r['name']) ?></label>
                  <button class="btn sm danger" type="submit">Delete</button>
                <?php endif ?>
              </form>
            <?php endif ?>
          </div>
        </td></tr>
      <?php endif ?>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<form class="card newform permform" method="post" action="<?= h($url) ?>" autocomplete="off" id="addRole">
  <h2>Add a role</h2>
  <?= $hidden('add') ?>
  <label class="field"><span class="lbl">Name</span><input name="name" maxlength="60" placeholder="For example: Guest presenter" required></label>
  <span class="lbl">What it may do</span>
  <?= $ticks([], false) ?>
  <button class="btn primary" type="submit">Add role</button>
</form>
