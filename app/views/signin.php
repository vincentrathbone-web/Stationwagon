<?php use function Stationwagon\h; use Stationwagon\App;
/** @var string $email @var ?string $error @var string $next @var bool $remember @var string $csrf */ ?>
<section class="narrow stack">
  <h1>Sign in</h1>
  <p class="lede">For station staff: admins, managers and DJs.</p>
  <form method="post" class="card" novalidate>
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <?php if ($error): ?><p class="alert bad" role="alert"><?= h($error) ?></p><?php endif ?>
    <div class="grid" style="grid-template-columns: 1fr">
      <label class="field" for="email"><span class="lbl">Email</span>
        <input id="email" name="email" type="email" value="<?= h($email) ?>" autocomplete="username" required autofocus></label>
      <label class="field" for="password"><span class="lbl">Password</span>
        <input id="password" name="password" type="password" autocomplete="current-password" required></label>
      <label class="swrow" for="remember"><input id="remember" name="remember" type="checkbox" value="1"<?= $remember ? ' checked' : '' ?>> Keep me signed in on this device for 30 days</label>
    </div>
    <button class="btn primary" type="submit">Sign in</button>
  </form>
  <p class="muted">Forgot your password? Ask the station’s admin to set a new one.</p>
</section>
<script src="<?= h(App::asset('assets/signin.js')) ?>" defer></script>
