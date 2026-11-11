<?php use function Stationwagon\h; use Stationwagon\{App, StationSettings};
/** @var array $values @var array $errors @var int $minLiveTimeout @var float $segment @var int $delay @var string $csrf @var string $presetName */
$groups = [];
foreach (StationSettings::FIELDS as $key => $f) {
    $groups[$f['group']][$key] = $f;
}
$unit = fn(string $u): string => $u === 's' ? ' s' : " $u";
$lufs = fn(float $v): string => str_replace('-', '−', number_format($v, 1));
// the usual loudness targets, marked on the slider (round 41)
$standards = [-16 => 'Apple Music, podcasts', -14 => 'Spotify, YouTube', -11 => 'Loud, like FM radio', -9 => 'Very loud'];
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
      <section class="card set" id="g-<?= h(strtolower(preg_replace('/[^A-Za-z]+/', '-', $group))) ?>">
        <h2><?= h($group) ?></h2>
        <?php foreach ($fields as $key => $f):
          $help = $f['help'];
          $min = $f['min'] ?? null;
          if ($key === 'live_timeout_seconds') {
              $min = $minLiveTimeout;
              $help .= sprintf(' At least %d s, since live audio arrives in %s s pieces.', $minLiveTimeout, StationSettings::show($segment));
          }
          $v = $values[$key]; ?>
          <div class="setrow<?= isset($errors[$key]) ? ' bad' : '' ?>">
            <div><label for="f-<?= h($key) ?>"><b><?= h($f['label']) ?></b></label><p><?= h($help) ?><?php if ($key === 'prep_master'): ?> The import preset now is “<?= h($presetName) ?>” (<a href="<?= h(App::url('manage/songprep')) ?>">change it in Song prep</a>).<?php endif ?></p>
              <?php if (isset($errors[$key])): ?><p class="err"><?= h($errors[$key]) ?></p><?php endif ?></div>
            <?php if (isset($f['choices'])): ?>
              <div class="ctl choice">
                <select id="f-<?= h($key) ?>" name="<?= h($key) ?>">
                  <?php foreach ($f['choices'] as $k => $label):
                    if (str_ends_with($key, '_kbps')) {
                        $label .= ' · ' . StationSettings::mbPerHour((int) $k) . ' MB an hour';
                    } ?>
                    <option value="<?= h((string) $k) ?>"<?= (string) $k === (string) $v ? ' selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach ?>
                </select>
                <?php if ($key === 'fade_shape'): ?>
                  <svg class="fadecurve" id="fadeCurve" viewBox="0 0 240 72" role="img" aria-label="The fade shape: the song going out and the one coming in">
                    <line x1="0" y1="66" x2="240" y2="66" class="axis"/>
                    <path class="out" d=""/><path class="in" d=""/>
                  </svg>
                <?php endif ?>
              </div>
            <?php elseif ($key === 'prep_target'): ?>
              <div class="loudwrap">
                <div class="ctl loudctl" id="loud"<?= (int) $values['prep_level'] === 1 ? '' : ' data-off' ?>>
                  <div class="loudtrack">
                    <input type="range" data-loud id="f-prep_target" name="prep_target" min="<?= h((string) $min) ?>" max="<?= h((string) $f['max']) ?>" step="0.5" value="<?= h((string) $v) ?>" aria-describedby="loudLegend">
                    <?php foreach ($standards as $mark => $what): ?><i class="loudmark lk<?= -$mark ?>" style="--at: <?= round(($mark - $min) / ($f['max'] - $min), 4) ?>" aria-hidden="true"></i><?php endforeach ?>
                  </div>
                  <output for="f-prep_target"><?= h($lufs((float) $v)) ?> LUFS</output>
                </div>
                <ul class="loudlegend" id="loudLegend">
                  <?php foreach ($standards as $mark => $what): ?><li><i class="lk<?= -$mark ?>"></i><b><?= h($lufs((float) $mark)) ?></b> <?= h($what) ?></li><?php endforeach ?>
                </ul>
              </div>
            <?php else: ?>
              <div class="ctl">
                <input type="range" id="f-<?= h($key) ?>" name="<?= h($key) ?>" min="<?= h((string) $min) ?>" max="<?= h((string) $f['max']) ?>" step="<?= h((string) $f['step']) ?>" value="<?= h((string) $v) ?>" data-unit="<?= h($unit($f['unit'])) ?>">
                <output for="f-<?= h($key) ?>"><?= h(StationSettings::show((float) $v) . $unit($f['unit'])) ?></output>
              </div>
            <?php endif ?>
          </div>
        <?php endforeach ?>
        <?php if ($group === 'Sound quality'): ?>
          <div class="setrow fixed" id="listenerDelay">
            <div><b>Listener delay</b><p>Listeners hear the station about this far behind the studio. It comes from the length of the stream’s pieces, which is fixed for now.</p></div>
            <div class="ctl"><span class="fixedval">About <?= $delay ?> s</span><output><?= h(StationSettings::show($segment)) ?> s pieces</output></div>
          </div>
        <?php endif ?>
      </section>
    <?php endforeach ?>
  </div>
  <div class="row-actions"><button class="btn primary" type="submit">Save changes</button></div>
</form>

<script src="<?= h(App::asset('assets/fades.js')) ?>"></script>
<script src="<?= h(App::asset('assets/loudness.js')) ?>"></script>
<script>
document.querySelectorAll('.soundform input[type=range]:not([data-loud])').forEach((el) => {
  const out = el.nextElementSibling;
  el.addEventListener('input', () => { out.textContent = String(+el.value) + el.dataset.unit; });
});
(() => {
  // The fade shape preview: the song going out (dim) and the one coming in (bright)
  const sel = document.getElementById('f-fade_shape'), svg = document.getElementById('fadeCurve');
  if (!sel || !svg) return;
  const W = 240, H = 60, TOP = 6, N = 48;
  const path = (fn) => Array.from({ length: N + 1 }, (_, i) => {
    const x = i / N;
    return (i ? 'L' : 'M') + (x * W).toFixed(1) + ' ' + (TOP + H * (1 - fn(x))).toFixed(1);
  }).join(' ');
  const draw = () => {
    svg.querySelector('.out').setAttribute('d', path((x) => SwFade.out(sel.value, x)));
    svg.querySelector('.in').setAttribute('d', path((x) => SwFade.in(sel.value, x)));
  };
  sel.addEventListener('change', draw);
  draw();
})();
</script>
