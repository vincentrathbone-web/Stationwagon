<?php use function Stationwagon\{h, clock}; use Stationwagon\{App, Staff};
/** @var array $me @var array $people @var array $grantable @var array $form @var int $open @var array $station @var string $csrf */
$in = $form['in'];
$err = $form['errors'];
$tz = $station['timezone'];
$when = fn(?string $utc) => $utc ? clock((float) strtotime($utc . ' UTC'), $tz, 'j M, H:i') : 'Never';
?>
<div class="vhead">
  <div>
    <h1>DJs</h1>
    <p>Everyone who can sign in to this station. DJs can go live from the studio; managers also run the library, playlists, schedule and DJs. Passwords are never shown: whoever sets one passes it on, and people can change their own on Your account.</p>
  </div>
</div>

<div class="tablewrap">
  <table>
    <thead><tr><th>Name</th><th>Role</th><th>Last signed in</th><th>Last live</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $p): $pid = (int) $p['id']; $mine = $pid === (int) $me['id']; $can = Staff::canManage($me, $p); ?>
      <tr>
        <td><b><?= h($p['name']) ?></b><?= $mine ? ' <span class="pill">You</span>' : '' ?><span class="sub"><?= h($p['email']) ?></span></td>
        <td><span class="pill <?= $p['role'] === 'dj' ? 'live' : 'auto' ?>"><?= h(Staff::ROLE_NAMES[$p['role']]) ?></span></td>
        <td><?= h($when($p['last_login_at'])) ?></td>
        <td><?= $p['last_live'] ? h(clock((float) $p['last_live'], $tz, 'j M, H:i')) : '—' ?></td>
        <td class="acts">
          <?php if ($mine): ?><a class="btn sm" href="<?= h(App::url('account')) ?>">Your account</a>
          <?php elseif ($can): ?><a class="btn sm" href="<?= h(App::url('manage/djs') . ($open === $pid ? '' : '?person=' . $pid)) ?>"><?= $open === $pid ? 'Close' : 'Change' ?></a><?php endif ?>
        </td>
      </tr>
      <?php if ($open === $pid && $can): ?>
        <tr class="editing"><td colspan="5">
          <div class="personform">
            <form method="post" action="<?= h(App::url('manage/djs')) ?>" class="formrow">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= $pid ?>">
              <label class="field"><span class="lbl">Role</span><select name="role"><?php foreach ($grantable as $r): ?><option value="<?= h($r) ?>"<?= $p['role'] === $r ? ' selected' : '' ?>><?= h(Staff::ROLE_NAMES[$r]) ?></option><?php endforeach ?></select></label>
              <button class="btn sm" type="submit">Change role</button>
            </form>
            <form method="post" action="<?= h(App::url('manage/djs')) ?>" class="formrow">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= $pid ?>">
              <label class="field"><span class="lbl">New password</span><input type="password" name="password" autocomplete="new-password" minlength="10" required><span class="hint">At least 10 characters. Signs them out elsewhere.</span></label>
              <button class="btn sm" type="submit">Set password</button>
            </form>
            <form method="post" action="<?= h(App::url('manage/djs')) ?>" class="formrow">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= $pid ?>">
              <label class="swrow"><input type="checkbox" required> Yes, remove <?= h($p['name']) ?></label>
              <button class="btn sm danger" type="submit">Remove</button>
            </form>
          </div>
        </td></tr>
      <?php endif ?>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<form class="card newform" method="post" action="<?= h(App::url('manage/djs')) ?>" autocomplete="off">
  <h2>Add someone</h2>
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="add">
  <div class="formrow">
    <label class="field<?= isset($err['name']) ? ' bad' : '' ?>"><span class="lbl">Name</span><input name="name" value="<?= h($in['name'] ?? '') ?>" required></label>
    <label class="field<?= isset($err['email']) ? ' bad' : '' ?>"><span class="lbl">Email (their sign-in)</span><input type="email" name="email" value="<?= h($in['email'] ?? '') ?>" required></label>
    <label class="field<?= isset($err['role']) ? ' bad' : '' ?>"><span class="lbl">Role</span><select name="role"><?php foreach ($grantable as $r): ?><option value="<?= h($r) ?>"<?= ($in['role'] ?? 'dj') === $r ? ' selected' : '' ?>><?= h(Staff::ROLE_NAMES[$r]) ?></option><?php endforeach ?></select></label>
    <label class="field<?= isset($err['password']) ? ' bad' : '' ?>"><span class="lbl">First password</span><input type="password" name="password" autocomplete="new-password" minlength="10" required><span class="hint">At least 10 characters</span></label>
    <button class="btn primary" type="submit">Add</button>
  </div>
</form>
