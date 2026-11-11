<?php use function Stationwagon\{h, megabytes}; use Stationwagon\App;
/**
 * The drop zone with Song prep's two switches and the loudness target (assets/upload.js, prep.js): the Music
 * library's and the Ads screen's. The page sets $dropRoute (where uploads and the switches are posted), $dropNoun
 * ('song' or 'ad'), $dropIntro and $relevel (the songs to re-level, or null where that doesn't apply), and has
 * $prep, $preset, $maxUpload, $csrf and $me (the loudness choices are on Sound & safety since round 64: this shows them).
 * @var array $prep @var array $preset @var int $maxUpload @var string $csrf @var string $dropRoute @var string $dropNoun @var string $dropIntro @var ?array $relevel
 */
[$prepMaster, $prepLevel, $prepTarget] = $prep;
[$presetName, $presetSettings] = $preset;
$lufs ??= fn(float $v) => str_replace('-', '−', number_format($v, 1));
// the usual loudness targets, marked on the slider (round 41)
$standards = [-16 => 'Apple Music, podcasts', -14 => 'Spotify, YouTube', -11 => 'Loud, like FM radio', -9 => 'Very loud'];
?>
<form class="drop" id="drop" method="post" enctype="multipart/form-data" action="<?= h(App::url($dropRoute)) ?>" data-max="<?= (int) $maxUpload ?>" data-preset="<?= h(json_encode($presetSettings, JSON_UNESCAPED_SLASHES)) ?>" data-preset-name="<?= h($presetName) ?>" data-target="<?= h((string) $prepTarget) ?>" data-master="<?= $prepMaster ? 1 : 0 ?>" data-level="<?= $prepLevel ? 1 : 0 ?>">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="upload">
  <div><b>Add <?= h($dropNoun) ?>s</b><br><span class="muted2"><?= $dropIntro ?> Up to <?= h(megabytes($maxUpload)) ?> per upload; the same recording twice is only stored once.</span>
    <p class="muted2 prepnote" id="prepNote">Prepared in your browser before upload: <?= $prepMaster ? 'mastered with “' . h($presetName) . '”' : 'not mastered' ?>, <?= $prepLevel ? 'levelled to <b>' . h($lufs($prepTarget)) . ' LUFS</b>' : 'kept at its own loudness' ?>.
      <?php if (Stationwagon\Auth::allows($me, 'sound')): ?><a href="<?= h(App::url('manage/sound')) ?>#g-loudness">Change this in Sound &amp; safety</a>.<?php endif ?></p>
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
    <input id="files" class="filepick" name="files[]" type="file" accept=".mp3,.wav,.flac,.m4a,.mp4,.aac,.ogg,.oga,.opus,.wma,audio/*" multiple>
    <label class="btn" for="files">Choose files</label>
    <button class="btn primary" type="submit">Upload</button>
  </div>
  <ol class="uplist" id="uplist" hidden></ol>
</form>
