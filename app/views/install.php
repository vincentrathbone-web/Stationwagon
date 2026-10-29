<?php use function Stationwagon\h; use Stationwagon\App;
/** @var array $in @var array $errors @var ?string $notice @var ?string $dbProblem @var array $checks @var bool $ready @var bool $needsKey @var array $timezones */
$field = function (string $name, string $label, string $hint = '', string $type = 'text', array $attrs = []) use ($in, $errors) {
    $value = $type === 'password' ? '' : ($in[$name] ?? '');
    $extra = '';
    foreach ($attrs as $k => $v) { $extra .= ' ' . $k . '="' . h($v) . '"'; }
    $err = $errors[$name] ?? null;
    return '<label class="field' . ($err ? ' bad' : '') . '" for="' . h($name) . '"><span class="lbl">' . h($label) . '</span>'
        . '<input id="' . h($name) . '" name="' . h($name) . '" type="' . h($type) . '" value="' . h($value) . '"' . $extra . '>'
        . ($err ? '<span class="err">' . h($err) . '</span>' : ($hint ? '<span class="hint">' . h($hint) . '</span>' : ''))
        . '</label>';
};
$choice = function (string $name, string $value, string $title, string $text, string $tag = '') use ($in) {
    return '<label class="choice"><input type="radio" name="' . h($name) . '" value="' . h($value) . '"' . (($in[$name] ?? '') === $value ? ' checked' : '')
        . ($tag === 'cPanel login' ? ' data-cpanel="1"' : '') . '><span><b>' . h($title) . '</b>'
        . ($tag !== '' ? ' <em class="tag' . ($tag === 'Recommended' ? ' easy' : '') . '">' . h($tag) . '</em>' : '')
        . '<small>' . $text . '</small></span></label>';
};
$err = fn(string $k) => isset($errors[$k]) ? '<p class="alert bad">' . h($errors[$k]) . '</p>' : '';
?>
<h1>Set up your station</h1>
<p class="lede">A few questions, each with the easy answer already picked. It takes about a minute.</p>

<section class="card">
  <h2>Server check</h2>
  <ul class="checks">
    <?php foreach ($checks as $c): ?>
      <li class="<?= $c['ok'] ? 'ok' : ($c['required'] ? 'bad' : 'warn') ?>">
        <span class="dot" aria-hidden="true"></span>
        <span class="what"><b><?= h($c['label']) ?></b><?php if (!$c['ok']): ?><small><?= h($c['why']) ?></small><?php endif ?></span>
        <span class="found"><?= h($c['found']) ?></span>
      </li>
    <?php endforeach ?>
  </ul>
  <?php if (!$ready): ?><p class="alert bad">Fix the red rows first; the installer can't continue until they pass.</p><?php endif ?>
</section>

<form method="post" class="stack" id="install-form" novalidate>
  <?php if ($needsKey): ?>
  <section class="card">
    <h2>Setup key</h2>
    <p class="muted">This copy was put on a public server, so it asks for the one-time key from <code>install.key</code> (next to <code>index.php</code>). The key is deleted once setup is done.</p>
    <div class="grid"><?= $field('setup_key', 'Setup key', '', 'text', ['autocomplete' => 'off', 'spellcheck' => 'false']) ?></div>
    <?php if (($in['setup_key'] ?? '') !== ''): ?><p class="muted">Filled in by setup.php.</p><?php endif ?>
  </section>
  <?php endif ?>
  <section class="card">
    <h2>1 · Database</h2>
    <?= $choice('database', 'new', 'Make one for me', 'A new database with its own user and password, made for you in cPanel.', 'cPanel login') ?>
    <?= $choice('database', 'manual', 'I’ve made one', 'You made it in cPanel → <b>MySQL Databases</b> and added a user with <b>All Privileges</b>. On cPanel both names start with your account name, for example <code>controllco_wordwave</code>.') ?>
    <?= $err('database') ?>
    <div class="sub" data-for="database=manual">
      <div class="grid">
        <?= $field('db_name', 'Database name') ?>
        <?= $field('db_user', 'Database user') ?>
        <?= $field('db_pass', 'Database password', '', 'password', ['autocomplete' => 'off']) ?>
        <?= $field('db_host', 'Database server', 'Almost always localhost') ?>
        <?= $field('db_prefix', 'Table prefix', 'Change it only to share one database with another app') ?>
        <?= $field('db_port', 'Port', '', 'text', ['inputmode' => 'numeric']) ?>
      </div>
      <?php if ($notice): ?><p class="alert ok" role="status"><?= h($notice) ?></p><?php endif ?>
      <button class="btn" name="action" value="test" type="submit">Test connection</button>
    </div>
    <?php if ($dbProblem): ?><p class="alert bad" role="alert"><?= h($dbProblem) ?></p><?php endif ?>
  </section>

  <section class="card">
    <h2>2 · Background jobs</h2>
    <p class="muted">The station plans its music ahead, makes podcast episodes after live shows and keeps its listener stats up to date.</p>
    <?= $choice('jobs', 'visits', 'Automatically, during visits', 'They run by themselves when listeners and staff use the station, at most every 5 minutes. Nothing to set up.', 'Recommended') ?>
    <?= $choice('jobs', 'cpanel', 'Also add a cron job for me', 'A cron job runs them every 5 minutes, even at quiet times. Added for you in cPanel.', 'cPanel login') ?>
    <?= $choice('jobs', 'manual', 'I’ll add the cron job myself', 'You get the exact line to paste into cPanel → Cron Jobs after setup.') ?>
    <?= $err('jobs') ?>
  </section>

  <section class="card" id="cpanel-login">
    <h2>3 · Your cPanel login</h2>
    <p class="muted">Some choices above are done for you in cPanel. Your login is used once, for this setup only, and never saved. (A cPanel API token works in place of the password.)</p>
    <div class="grid">
      <?= $field('cpanel_user', 'cPanel username', '', 'text', ['autocomplete' => 'off']) ?>
      <?= $field('cpanel_pass', 'cPanel password', '', 'password', ['autocomplete' => 'off']) ?>
    </div>
  </section>

  <section class="card">
    <h2><span class="n-last">4</span> · Station</h2>
    <div class="grid">
      <?= $field('station_name', 'Station name', 'Shown to listeners, e.g. Radio WordWave') ?>
      <label class="field<?= isset($errors['timezone']) ? ' bad' : '' ?>" for="timezone"><span class="lbl">Timezone</span>
        <select id="timezone" name="timezone">
          <?php foreach ($timezones as $tz): ?><option<?= $tz === $in['timezone'] ? ' selected' : '' ?>><?= h($tz) ?></option><?php endforeach ?>
        </select>
        <span class="hint">The schedule uses this clock</span>
      </label>
    </div>
    <h3>Your sign-in</h3>
    <p class="muted">You'll be the station's first admin. You can add managers and DJs later.</p>
    <div class="grid">
      <?= $field('admin_name', 'Your name', '', 'text', ['autocomplete' => 'name']) ?>
      <?= $field('admin_email', 'Email', '', 'email', ['autocomplete' => 'email']) ?>
      <?= $field('admin_password', 'Password', 'At least 10 characters', 'password', ['autocomplete' => 'new-password']) ?>
    </div>
  </section>

  <div class="actions">
    <button class="btn primary" name="action" value="install" type="submit"<?= $ready ? '' : ' disabled' ?>>Set up my station</button>
    <span class="muted wait" hidden>Setting up… this takes about a minute.</span>
  </div>
</form>
<script src="<?= h(App::asset('assets/install.js')) ?>" defer></script>
