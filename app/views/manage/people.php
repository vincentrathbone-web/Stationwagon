<?php use function Stationwagon\{h, clock}; use Stationwagon\{App, Permissions};
/** @var array $me @var array $people @var \Stationwagon\Staff $staff @var array $roles @var array $grantable @var array $form @var int $open @var array $station @var string $csrf */
$in = $form['in'];
$err = $form['errors'];
$tz = $station['timezone'];
$when = fn(?string $utc) => $utc ? clock((float) strtotime($utc . ' UTC'), $tz, 'j M, H:i') : 'Never';
$url = App::url('manage/people');
$hidden = fn(string $action, int $id = 0) => '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="' . h($action) . '">' . ($id ? '<input type="hidden" name="id" value="' . $id . '">' : '');
$byKey = array_column($roles, null, 'key');
$roleName = fn(string $key) => $byKey[$key]['name'] ?? $key;
$roleOptions = function (string $selected) use ($grantable, $roleName): string {
    $out = '';
    foreach ($grantable as $r) {
        $out .= '<option value="' . h($r) . '"' . ($selected === $r ? ' selected' : '') . '>' . h($roleName($r)) . '</option>';
    }
    return $out;
};
$isAdmin = $me['role'] === 'admin';
?>
<div class="vhead">
  <div>
    <h1>People</h1>
    <p>Everyone who can sign in to this station. Each person may do what their role allows<?= $isAdmin ? ' (set under <a href="' . h(App::url('manage/roles')) . '">Roles</a>)' : '' ?>; everyone can go live in their own scheduled blocks, keep their running order and edit their own podcast episodes. Passwords are never shown: whoever sets one passes it on, and people can change their own on Your account.</p>
  </div>
</div>

<div class="tablewrap">
  <table class="people">
    <thead><tr><th>Name</th><th>Role</th><th>May do</th><th>Last signed in</th><th>Last live</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $p): $pid = (int) $p['id']; $mine = $pid === (int) $me['id']; $can = $staff->canManage($me, $p); $blocked = !empty($p['blocked_at']); ?>
      <tr<?= $blocked ? ' class="blocked"' : '' ?> data-person="<?= $pid ?>">
        <td><b><?= h($p['name']) ?></b><?= $mine ? ' <span class="pill">You</span>' : '' ?><?= $blocked ? ' <span class="pill bad">Blocked</span>' : '' ?><span class="sub"><?= h($p['email']) ?></span></td>
        <td><span class="pill <?= $p['role'] === 'admin' || $p['role'] === 'manager' ? 'auto' : 'live' ?>"><?= h($roleName($p['role'])) ?></span></td>
        <td class="cando"><?= h(Permissions::summary(Permissions::of($p))) ?></td>
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
              <label class="field"><span class="lbl">Role</span><select name="role"><?= $roleOptions($p['role']) ?></select></label>
              <button class="btn sm" type="submit">Change role</button>
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
    <label class="field<?= isset($err['role']) ? ' bad' : '' ?>"><span class="lbl">Role</span><select name="role"><?= $roleOptions((string) ($in['role'] ?? 'dj')) ?></select></label>
    <label class="field<?= isset($err['password']) ? ' bad' : '' ?>"><span class="lbl">First password</span><input type="password" name="password" autocomplete="new-password" minlength="10" required><span class="hint">At least 10 characters</span></label>
    <button class="btn primary" type="submit">Add</button>
  </div>
  <p class="hint">What each role may do:
    <?php foreach ($roles as $i => $r): if (!in_array($r['key'], $grantable, true)) { continue; } ?>
      <b><?= h($r['name']) ?></b> <?= h(Permissions::summary($r['permissions'])) ?>.
    <?php endforeach ?>
  </p>
</form>
