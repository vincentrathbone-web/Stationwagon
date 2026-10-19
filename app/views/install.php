<?php use function Stationwagon\h;
/** @var array $in @var array $errors @var ?string $notice @var ?string $dbProblem @var array $checks @var bool $ready @var bool $needsKey @var array $timezones */
$field = function (string $name, string $label, string $hint = '', string $type = 'text', array $attrs = []) use ($in, $errors) {
    $value = $type === 'password' && $name === 'admin_password' ? '' : ($in[$name] ?? '');
    $extra = '';
    foreach ($attrs as $k => $v) { $extra .= ' ' . $k . '="' . h($v) . '"'; }
    $err = $errors[$name] ?? null;
    return '<label class="field' . ($err ? ' bad' : '') . '" for="' . h($name) . '"><span class="lbl">' . h($label) . '</span>'
        . '<input id="' . h($name) . '" name="' . h($name) . '" type="' . h($type) . '" value="' . h($value) . '"' . $extra . '>'
        . ($err ? '<span class="err">' . h($err) . '</span>' : ($hint ? '<span class="hint">' . h($hint) . '</span>' : ''))
        . '</label>';
};
?>
<h1>Set up your station</h1>
<p class="lede">Three steps: the database you made in cPanel, your station's name, and your own sign-in.</p>

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

<form method="post" class="stack" novalidate>
  <?php if ($needsKey): ?>
  <section class="card">
    <h2>Setup key</h2>
    <p class="muted">This copy was put on a public server, so it asks for the one-time key from <code>install.key</code> (next to <code>index.php</code>). The key is deleted once setup is done.</p>
    <div class="grid"><?= $field('setup_key', 'Setup key', '', 'text', ['autocomplete' => 'off', 'spellcheck' => 'false']) ?></div>
  </section>
  <?php endif ?>
  <section class="card">
    <h2>1 · Database</h2>
    <p class="muted">In cPanel → <b>MySQL Databases</b>: create a database and a user, then add the user to the database with <b>All Privileges</b>. On cPanel both names start with your account name, for example <code>controllco_wordwave</code>.</p>
    <div class="grid">
      <?= $field('db_name', 'Database name') ?>
      <?= $field('db_user', 'Database user') ?>
      <?= $field('db_pass', 'Database password', '', 'password', ['autocomplete' => 'off']) ?>
      <?= $field('db_host', 'Database server', 'Almost always localhost') ?>
      <?= $field('db_prefix', 'Table prefix', 'Change it only to share one database with another app') ?>
      <?= $field('db_port', 'Port', '', 'text', ['inputmode' => 'numeric']) ?>
    </div>
    <?php if ($notice): ?><p class="alert ok" role="status"><?= h($notice) ?></p><?php endif ?>
    <?php if ($dbProblem): ?><p class="alert bad" role="alert"><?= h($dbProblem) ?></p><?php endif ?>
    <button class="btn" name="action" value="test" type="submit">Test connection</button>
  </section>

  <section class="card">
    <h2>2 · Station</h2>
    <div class="grid">
      <?= $field('station_name', 'Station name', 'Shown to listeners, e.g. Radio WordWave') ?>
      <label class="field<?= isset($errors['timezone']) ? ' bad' : '' ?>" for="timezone"><span class="lbl">Timezone</span>
        <select id="timezone" name="timezone">
          <?php foreach ($timezones as $tz): ?><option<?= $tz === $in['timezone'] ? ' selected' : '' ?>><?= h($tz) ?></option><?php endforeach ?>
        </select>
        <span class="hint">The schedule uses this clock</span>
      </label>
    </div>
  </section>

  <section class="card">
    <h2>3 · Your sign-in</h2>
    <p class="muted">You'll be the station's first admin. You can add managers and DJs later.</p>
    <div class="grid">
      <?= $field('admin_name', 'Your name', '', 'text', ['autocomplete' => 'name']) ?>
      <?= $field('admin_email', 'Email', '', 'email', ['autocomplete' => 'email']) ?>
      <?= $field('admin_password', 'Password', 'At least 10 characters', 'password', ['autocomplete' => 'new-password']) ?>
    </div>
  </section>

  <div class="actions">
    <button class="btn primary" name="action" value="install" type="submit"<?= $ready ? '' : ' disabled' ?>>Install Stationwagon</button>
  </div>
</form>
