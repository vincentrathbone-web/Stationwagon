<?php use function Stationwagon\h; use Stationwagon\{App, StationSettings};
/** @var array $values @var array $errors @var int $minLiveTimeout @var float $segment @var string $csrf */
$groups = [];
foreach (StationSettings::FIELDS as $key => $f) {
    $groups[$f[0]][$key] = $f;
}
$unit = fn(string $u): string => $u === 's' ? ' s' : " $u";
?>
<form method="post" action="<?= h(App::url('manage/sound')) ?>" class="soundform">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <div class="vhead vhead-row">
    <div>
      <h1>Sound &amp; safety</h1>
      <p>How songs blend, how live shows cut in, and what happens when a DJ goes quiet. Changes apply from the next song.</p>
    </div>
    <button class="btn primary" type="submit">Save changes</button>
  </div>

  <div class="cols">
    <?php foreach ($groups as $group => $fields): ?>
      <section class="card set">
        <h2><?= h($group) ?></h2>
        <?php foreach ($fields as $key => [, $label, $help, $min, $max, $step, $u]):
          if ($key === 'live_timeout_seconds') {
              $min = $minLiveTimeout;
              $help .= sprintf(' At least %d s, since live audio arrives in %s s pieces.', $minLiveTimeout, StationSettings::show($segment));
          }
          $v = $values[$key]; ?>
          <div class="setrow<?= isset($errors[$key]) ? ' bad' : '' ?>">
            <div><label for="f-<?= h($key) ?>"><b><?= h($label) ?></b></label><p><?= h($help) ?></p>
              <?php if (isset($errors[$key])): ?><p class="err"><?= h($errors[$key]) ?></p><?php endif ?></div>
            <div class="ctl">
              <input type="range" id="f-<?= h($key) ?>" name="<?= h($key) ?>" min="<?= h((string) $min) ?>" max="<?= h((string) $max) ?>" step="<?= h((string) $step) ?>" value="<?= h((string) $v) ?>" data-unit="<?= h($unit($u)) ?>">
              <output for="f-<?= h($key) ?>"><?= h(StationSettings::show((float) $v) . $unit($u)) ?></output>
            </div>
          </div>
        <?php endforeach ?>
      </section>
    <?php endforeach ?>
  </div>
  <div class="row-actions"><button class="btn primary" type="submit">Save changes</button></div>
</form>

<section class="card later">
  <h2>Coming with the player and studio</h2>
  <p class="muted2">These settings from the design arrive in v0.4, when the two-deck listener player and the live studio move onto the app:
    fade shape, music under the voice (ducking), silence detection, the DJ rejoin window, sound quality and listener delay.</p>
</section>

<script>
document.querySelectorAll('.soundform input[type=range]').forEach((el) => {
  const out = el.nextElementSibling;
  el.addEventListener('input', () => { out.textContent = String(+el.value) + el.dataset.unit; });
});
</script>
