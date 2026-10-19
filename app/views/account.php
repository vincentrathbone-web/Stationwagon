<?php use function Stationwagon\h;
/** @var array $me @var ?string $error @var ?string $notice @var string $csrf */ ?>
<section class="narrow stack">
  <h1>Your account</h1>
  <p class="lede"><?= h($me['name']) ?> · <?= h($me['email']) ?> · <span class="role"><?= h($me['role']) ?></span></p>
  <form method="post" class="card" novalidate>
    <h2>Change password</h2>
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <?php if ($notice): ?><p class="alert ok" role="status"><?= h($notice) ?></p><?php endif ?>
    <?php if ($error): ?><p class="alert bad" role="alert"><?= h($error) ?></p><?php endif ?>
    <div class="grid" style="grid-template-columns: 1fr">
      <label class="field" for="current_password"><span class="lbl">Current password</span>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required></label>
      <label class="field" for="new_password"><span class="lbl">New password</span>
        <input id="new_password" name="new_password" type="password" autocomplete="new-password" required><span class="hint">At least 10 characters</span></label>
      <label class="field" for="confirm_password"><span class="lbl">New password again</span>
        <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required></label>
    </div>
    <button class="btn primary" type="submit">Change password</button>
  </form>
</section>
