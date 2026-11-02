<?php use function Stationwagon\{h, clock}; use Stationwagon\{App, Permissions, Staff};
/** @var array $me @var array $people @var array $grantable @var array $mayTick @var array $form @var int $open @var array $station @var string $csrf */
$in = $form['in'];
$err = $form['errors'];
$tz = $station['timezone'];
$when = fn(?string $utc) => $utc ? clock((float) strtotime($utc . ' UTC'), $tz, 'j M, H:i') : 'Never';
$url = App::url('manage/people');
$hidden = fn(string $action, int $id = 0) => '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="' . h($action) . '">' . ($id ? '<input type="hidden" name="id" value="' . $id . '">' : '');
$canDo = function (array $p): string {
    $have = Permissions::of($p);
    if (count($have) === count(Permissions::ALL)) {
        return 'Everything';
    }
    return $have ? implode(', ', array_map(fn($k) => Permissions::ALL[$k][0], $have)) : 'Their own shows';
};
// The tick boxes; $have is what's ticked, $locked disables them all (admins)
$ticks = function (array $have, bool $locked) use ($mayTick): string {
    $out = '<input type="hidden" name="perms" value="1"><div class="perms">';
    foreach (Permissions::ALL as $k => [$label, $what]) {
        $off = $locked || !in_array($k, $mayTick, true);
        $out .= '<label class="perm' . ($off ? ' off' : '') . '"><input type="checkbox" name="perm[]" value="' . h($k) . '"' . (in_array($k, $have, true) ? ' checked' : '') . ($off ? ' disabled' : '') . '>'
            . '<span><b>' . h($label) . '</b><small>' . h($what) . '</small></span></label>';
    }
    return $out . '</div>';
};
?>
<div class="vhead">
  <div>
    <h1>People</h1>
    <p>Everyone who can sign in to this station. Every DJ can go live in their own block, keep their running order and edit their own podcast episodes; the ticks add what else each person may do. Managers start with every tick, admins always have them all. Passwords are never shown: whoever sets one passes it on, and people can change their own on Your account.</p>
  </div>
</div>

<div class="tablewrap">
  <table class="people">
    <thead><tr><th>Name</th><th>Role</th><th>May do</th><th>Last signed in</th><th>Last live</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $p): $pid = (int) $p['id']; $mine = $pid === (int) $me['id']; $can = Staff::canManage($me, $p); $blocked = !empty($p['blocked_at']); ?>
      <tr<?= $blocked ? ' class="blocked"' : '' ?> data-person="<?= $pid ?>">
        <td><b><?= h($p['name']) ?></b><?= $mine ? ' <span class="pill">You</span>' : '' ?><?= $blocked ? ' <span class="pill bad">Blocked</span>' : '' ?><span class="sub"><?= h($p['email']) ?></span></td>
        <td><span class="pill <?= $p['role'] === 'dj' ? 'live' : 'auto' ?>"><?= h(Staff::ROLE_NAMES[$p['role']]) ?></span></td>
        <td class="cando"><?= h($canDo($p)) ?></td>
        <td><?= h($when($p['last_login_at'])) ?></td>
        <td><?= $p['last_live'] ? h(clock((float) $p['last_live'], $tz, 'j M, H:i')) : '—' ?></td>
        <td class="acts">
          <?php if ($mine): ?><a class="btn sm" href="<?= h(App::url('account')) ?>">Your account</a>
          <?php elseif ($can): ?><a class="btn sm" href="<?= h($url . ($open === $pid ? '' : '?person=' . $pid)) ?>"><?= $open === $pid ? 'Close' : 'Change' ?></a><?php endif ?>
        </td>
      </tr>
      <?php if ($open === $pid && $can): ?>
        <tr class="editing"><td colspan="6">
          <div class="personform">
            <form method="post" action="<?= h($url) ?>" class="formrow" autocomplete="off">
              <?= $hidden('details', $pid) ?>
              <label class="field"><span class="lbl">Name</span><input name="name" value="<?= h($p['name']) ?>" required></label>
              <label class="field"><span class="lbl">Email (their sign-in)</span><input type="email" name="email" value="<?= h($p['email']) ?>" required></label>
              <button class="btn sm" type="submit">Save name and email</button>
            </form>
            <form method="post" action="<?= h($url) ?>" class="formrow">
              <?= $hidden('role', $pid) ?>
              <label class="field"><span class="lbl">Role</span><select name="role"><?php foreach ($grantable as $r): ?><option value="<?= h($r) ?>"<?= $p['role'] === $r ? ' selected' : '' ?>><?= h(Staff::ROLE_NAMES[$r]) ?></option><?php endforeach ?></select></label>
              <button class="btn sm" type="submit">Change role</button>
            </form>
            <form method="post" action="<?= h($url) ?>" class="permform">
              <?= $hidden('permissions', $pid) ?>
              <span class="lbl">What <?= h($p['name']) ?> may do</span>
              <?= $ticks(Permissions::of($p), $p['role'] === 'admin') ?>
              <?php if ($p['role'] === 'admin'): ?><p class="hint">Admins may always do everything.</p>
              <?php else: ?><button class="btn sm" type="submit">Save what they may do</button><?php endif ?>
            </form>
            <form method="post" action="<?= h($url) ?>" class="formrow">
              <?= $hidden('password', $pid) ?>
              <label class="field"><span class="lbl">New password</span><input type="password" name="password" autocomplete="new-password" minlength="10" required><span class="hint">At least 10 characters. Signs them out elsewhere.</span></label>
              <button class="btn sm" type="submit">Set password</button>
            </form>
            <?php if ($blocked): ?>
              <form method="post" action="<?= h($url) ?>" class="formrow">
                <?= $hidden('unblock', $pid) ?>
                <p class="hint grow">Blocked since <?= h($when($p['blocked_at'])) ?>. They can’t sign in.</p>
                <button class="btn sm" type="submit">Unblock</button>
              </form>
            <?php else: ?>
              <form method="post" action="<?= h($url) ?>" class="formrow">
                <?= $hidden('block', $pid) ?>
                <label class="swrow"><input type="checkbox" required> Yes, block <?= h($p['name']) ?>: sign them out everywhere and end their live show if they’re on air</label>
                <button class="btn sm danger" type="submit">Block</button>
              </form>
            <?php endif ?>
            <form method="post" action="<?= h($url) ?>" class="formrow">
              <?= $hidden('remove', $pid) ?>
              <label class="swrow"><input type="checkbox" required> Yes, remove <?= h($p['name']) ?> (their past shows stay)</label>
              <button class="btn sm danger" type="submit">Remove</button>
            </form>
          </div>
        </td></tr>
      <?php endif ?>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<form class="card newform" method="post" action="<?= h($url) ?>" autocomplete="off" id="addPerson">
  <h2>Add someone</h2>
  <?= $hidden('add') ?>
  <div class="formrow">
    <label class="field<?= isset($err['name']) ? ' bad' : '' ?>"><span class="lbl">Name</span><input name="name" value="<?= h($in['name'] ?? '') ?>" required></label>
    <label class="field<?= isset($err['email']) ? ' bad' : '' ?>"><span class="lbl">Email (their sign-in)</span><input type="email" name="email" value="<?= h($in['email'] ?? '') ?>" required></label>
    <label class="field<?= isset($err['role']) ? ' bad' : '' ?>"><span class="lbl">Role</span><select name="role"><?php foreach ($grantable as $r): ?><option value="<?= h($r) ?>"<?= ($in['role'] ?? 'dj') === $r ? ' selected' : '' ?>><?= h(Staff::ROLE_NAMES[$r]) ?></option><?php endforeach ?></select></label>
    <label class="field<?= isset($err['password']) ? ' bad' : '' ?>"><span class="lbl">First password</span><input type="password" name="password" autocomplete="new-password" minlength="10" required><span class="hint">At least 10 characters</span></label>
  </div>
  <div class="permform">
    <span class="lbl">What they may do</span>
    <?php $role = (string) ($in['role'] ?? 'dj'); ?>
    <?= $ticks(isset($in['perms']) ? array_map('strval', (array) ($in['perm'] ?? [])) : array_values(array_intersect(Permissions::defaults($role), $mayTick)), $role === 'admin') ?>
  </div>
  <button class="btn primary" type="submit">Add</button>
</form>
<script>
// The ticks follow the role picked: a role's usual (only what you may hand out); admins have everything
(function () {
  var f = document.getElementById('addPerson'), sel = f.querySelector('select[name=role]');
  var usual = <?= json_encode(array_map(fn($r) => Permissions::defaults($r), array_combine(array_keys(Staff::ROLE_NAMES), array_keys(Staff::ROLE_NAMES)))) ?>;
  var may = <?= json_encode($mayTick) ?>;
  sel.addEventListener('change', function () {
    var r = sel.value;
    f.querySelectorAll('.perm input').forEach(function (b) {
      b.checked = r === 'admin' || (usual[r].indexOf(b.value) >= 0 && may.indexOf(b.value) >= 0);
      b.disabled = r === 'admin' || may.indexOf(b.value) < 0;
      b.closest('.perm').classList.toggle('off', b.disabled);
    });
  });
})();
</script>
