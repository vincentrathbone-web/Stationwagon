<?php use function Stationwagon\{h, megabytes}; use Stationwagon\App;
/**
 * The drop zone with Song prep's two switches and the loudness target (assets/upload.js, prep.js): the Music
 * library's and the Ads screen's. The page sets $dropRoute (where uploads and the switches are posted), $dropNoun
 * ('song' or 'ad'), $dropIntro and $relevel (the songs to re-level, or null where that doesn't apply), and has
 * $prep, $preset, $maxUpload and $csrf.
 * @var array $prep @var array $preset @var int $maxUpload @var string $csrf @var string $dropRoute @var string $dropNoun @var string $dropIntro @var ?array $relevel
 */
[$prepMaster, $prepLevel, $prepTarget] = $prep;
[$presetName, $presetSettings] = $preset;
$lufs ??= fn(float $v) => str_replace('-', '−', number_format($v, 1));
// the usual loudness targets, marked on the slider (round 41)
$standards = [-16 => 'Apple Music, podcasts', -14 => 'Spotify, YouTube', -11 => 'Loud, like FM radio', -9 => 'Very loud'];
?>
<form class="drop" id="drop" method="post" enctype="multipart/form-data" action="<?= h(App::url($dropRoute)) ?>" data-max="<?= (int) $maxUpload ?>" data-preset="<?= h(json_encode($presetSettings, JSON_UNESCAPED_SLASHES)) ?>" data-preset-name="<?= h($presetName) ?>" data-target="<?= h((string) $prepTarget) ?>">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="upload">
  <div><b>Add <?= h($dropNoun) ?>s</b><br><span class="muted2"><?= $dropIntro ?> Up to <?= h(megabytes($maxUpload)) ?> per upload; the same recording twice is only stored once.</span>
    <div class="prep-opts" id="prepOpts">
      <label class="swrow" title="EQ and compression with the import preset, tried and set on the Song prep screen"><input class="sw" type="checkbox" name="master" value="1" id="prepMaster"<?= $prepMaster ? ' checked' : '' ?>> Apply pre-processing <span class="muted2">(mastering with “<?= h($presetName) ?>”, <a href="<?= h(App::url('manage/songprep')) ?>">change in Song prep</a>)</span></label>
      <label class="swrow" title="Every song plays at the same loudness"><input class="sw" type="checkbox" name="level" value="1" id="prepLevel"<?= $prepLevel ? ' checked' : '' ?>> Loudness normalization <span class="muted2">(<span id="prepTargetSw"><?= h($lufs($prepTarget)) ?></span> LUFS)</span></label>
      <span class="muted2" id="prepSaved" aria-live="polite"></span>
    </div>
    <div class="loud" id="loud"<?= $prepLevel ? '' : ' data-off'?>>
      <label class="loudhead" for="prepTarget">Loudness target <b><span id="prepTargetOut"><?= h($lufs($prepTarget)) ?></span> LUFS</b> <span class="muted2">every song is levelled to this; louder squeezes more, quieter keeps more dynamics</span></label>
      <div class="loudtrack">
        <input type="range" id="prepTarget" name="target" min="<?= (int) Stationwagon\StationSettings::TARGET_MIN ?>" max="<?= (int) Stationwagon\StationSettings::TARGET_MAX ?>" step="0.5" value="<?= h((string) $prepTarget) ?>" aria-describedby="loudLegend">
        <?php foreach ($standards as $v => $what): ?><i class="loudmark lk<?= -$v ?>" style="--at: <?= round(($v - Stationwagon\StationSettings::TARGET_MIN) / (Stationwagon\StationSettings::TARGET_MAX - Stationwagon\StationSettings::TARGET_MIN), 4) ?>" aria-hidden="true"></i><?php endforeach ?>
      </div>
      <ul class="loudlegend" id="loudLegend">
        <?php foreach ($standards as $v => $what): ?><li><i class="lk<?= -$v ?>"></i><b><?= h($lufs((float) $v)) ?></b> <?= h($what) ?></li><?php endforeach ?>
      </ul>
    </div>
    <?php if ($relevel !== null): ?>
    <div class="relevel" id="relevel" data-songs="<?= h(json_encode($relevel, JSON_UNESCAPED_SLASHES)) ?>" data-apply="<?= h(App::url('manage/songprep')) ?>" data-original="<?= h(App::url('manage/songprep/original')) ?>" hidden>
      <p id="relevelText"></p>
      <div class="spbtns"><button class="btn primary" type="button" id="relevelGo">Re-level them now</button> <button class="btn" type="button" id="relevelSkip">Only new uploads</button></div>
      <p class="muted2">Each song is made again from its original in this browser, with the mastering it was made with, so only its loudness changes. Keep this page open until it’s done; a song that is on air waits until it isn’t.</p>
      <ol class="uplist" id="relevelList" hidden></ol>
    </div>
    <p class="muted2" id="relevelLater" hidden><span></span> <button class="btn sm" type="button" id="relevelOpen">Re-level</button></p>
    <?php endif ?>
  </div>
  <div class="drop-actions">
    <input id="files" name="files[]" type="file" accept=".mp3,.wav,.flac,.m4a,.mp4,.aac,.ogg,.oga,.opus,.wma,audio/*" multiple>
    <button class="btn primary" type="submit">Upload</button>
  </div>
  <ol class="uplist" id="uplist" hidden></ol>
</form>
