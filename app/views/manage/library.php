<?php use function Stationwagon\{h, duration, megabytes}; use Stationwagon\App;
/** @var array $tracks @var string $q @var int $total @var int $bytes @var int $confirm @var int $edit @var int $maxUpload @var int $prepKbps @var array $prep @var array $preset @var array $relevel @var string $csrf */
[$prepMaster, $prepLevel, $prepTarget] = $prep;
$lufs = fn(float $v) => str_replace('-', '−', number_format($v, 1));
// the usual loudness targets, marked on the slider (round 41)
$standards = [-16 => 'Apple Music, podcasts', -14 => 'Spotify, YouTube', -11 => 'Loud, like FM radio', -9 => 'Very loud'];
[$presetName, $presetSettings] = $preset;
?>
<div class="vhead">
  <div>
    <h1>Music library</h1>
    <p><?= $total ?> songs · <?= h(megabytes($bytes)) ?>. New songs join the AutoDJ straight away; playlists and the schedule decide when they play.</p>
  </div>
</div>

<form class="drop" id="drop" method="post" enctype="multipart/form-data" action="<?= h(App::url('manage/library')) ?>" data-max="<?= (int) $maxUpload ?>" data-preset="<?= h(json_encode($presetSettings, JSON_UNESCAPED_SLASHES)) ?>" data-preset-name="<?= h($presetName) ?>" data-target="<?= h((string) $prepTarget) ?>">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="upload">
  <div><b>Add songs</b><br><span class="muted2">Drop MP3 files here, or choose them. Your browser first prepares each song as chosen below and trims silence at both ends. Up to <?= h(megabytes($maxUpload)) ?> per upload; the same song twice is only stored once.</span>
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
    <div class="relevel" id="relevel" data-songs="<?= h(json_encode($relevel, JSON_UNESCAPED_SLASHES)) ?>" data-apply="<?= h(App::url('manage/songprep')) ?>" data-original="<?= h(App::url('manage/songprep/original')) ?>" hidden>
      <p id="relevelText"></p>
      <div class="spbtns"><button class="btn primary" type="button" id="relevelGo">Re-level them now</button> <button class="btn" type="button" id="relevelSkip">Only new uploads</button></div>
      <p class="muted2">Each song is made again from its original in this browser, with the mastering it was made with, so only its loudness changes. Keep this page open until it’s done; a song that is on air waits until it isn’t.</p>
      <ol class="uplist" id="relevelList" hidden></ol>
    </div>
    <p class="muted2" id="relevelLater" hidden><span></span> <button class="btn sm" type="button" id="relevelOpen">Re-level</button></p>
  </div>
  <div class="drop-actions">
    <input id="files" name="files[]" type="file" accept=".mp3,audio/mpeg" multiple>
    <button class="btn primary" type="submit">Upload</button>
  </div>
  <ol class="uplist" id="uplist" hidden></ol>
</form>

<form class="toolbar" method="get" action="<?= h(App::url('manage/library')) ?>" role="search">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search title or artist" aria-label="Search the library">
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== ''): ?><a class="btn" href="<?= h(App::url('manage/library')) ?>">Show all</a><?php endif ?>
</form>

<div class="tablewrap">
  <table>
    <thead><tr><th>Title</th><th>Artist</th><th class="num">Length</th><th class="num">Playlists</th><th class="num">Size</th><th></th></tr></thead>
    <tbody>
    <?php if (!$tracks): ?>
      <tr><td colspan="6" class="muted2"><?= $q !== '' ? 'No songs match “' . h($q) . '”.' : 'No songs yet. Add some above.' ?></td></tr>
    <?php endif ?>
    <?php foreach ($tracks as $t): $id = (int) $t['id']; ?>
      <?php if ($edit === $id): ?>
        <tr class="editing"><td colspan="6">
          <form method="post" action="<?= h(App::url('manage/library')) ?>" class="editrow">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
            <label class="field"><span class="lbl">Title</span><input name="title" value="<?= h($t['title']) ?>" required></label>
            <label class="field"><span class="lbl">Artist</span><input name="artist" value="<?= h($t['artist'] ?? '') ?>"></label>
            <button class="btn primary sm" type="submit">Save</button>
            <a class="btn sm" href="<?= h(App::url('manage/library') . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>">Cancel</a>
          </form>
        </td></tr>
      <?php else: ?>
        <tr>
          <td><b><?= h($t['title']) ?></b><?php if ((int) ($t['mastered'] ?? 0)): ?> <span class="pill auto" title="Mastered with “<?= h($t['preset'] ?? 'Audition chain') ?>”">Mastered</span><?php endif ?><?php if ($t['loudness_lufs'] !== null): ?> <span class="pill auto" title="<?= h('Levelled to ' . $lufs((float) $t['loudness_lufs']) . ' LUFS'
            . ($t['level_target'] !== null ? ' (target ' . $lufs((float) $t['level_target']) . ')' : '')
            . ($t['level_gain_db'] !== null ? ': ' . ((float) $t['level_gain_db'] >= 0 ? '+' : '') . $lufs((float) $t['level_gain_db']) . ' dB from ' . $lufs((float) $t['source_lufs']) . ' LUFS' : '')) ?>">Levelled</span><?php endif ?><?php if ($t['album']): ?><span class="sub"><?= h($t['album']) ?></span><?php endif ?></td>
          <td><?= h($t['artist'] ?? '') ?></td>
          <td class="num"><?= h(duration((float) $t['duration_s'])) ?></td>
          <td class="num"><?= (int) $t['playlists'] ?></td>
          <td class="num"><?= h(megabytes((int) $t['bytes'])) ?><span class="sub"><?= (int) $t['bitrate_kbps'] ?> kbps</span></td>
          <td class="acts">
            <?php if ($confirm === $id): ?>
              <span class="warn2">Remove from the library?</span>
              <?= $post('manage/library', 'Yes, remove', ['action' => 'delete', 'id' => $id, 'q' => $q], 'btn sm danger') ?>
              <a class="btn sm" href="<?= h(App::url('manage/library') . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>">Keep</a>
            <?php else: ?>
              <a class="btn sm" href="<?= h(App::url('manage/library') . '?' . http_build_query(array_filter(['q' => $q, 'edit' => $id]))) ?>">Edit</a>
              <a class="btn sm" href="<?= h(App::url('manage/songprep') . '?song=' . $id) ?>" title="Try mastering presets on this song">Song prep</a>
              <a class="btn sm" href="<?= h(App::url('manage/library') . '?' . http_build_query(array_filter(['q' => $q, 'confirm' => $id]))) ?>">Remove</a>
            <?php endif ?>
          </td>
        </tr>
      <?php endif ?>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
<script src="<?= h(App::asset('assets/prep-core.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/prep.js')) ?>" data-kbps="<?= (int) $prepKbps ?>" data-worker="<?= h(App::asset('assets/prep-worker.js', ['assets/prep-core.js', 'assets/master-core.js'])) ?>" defer></script>
<script src="<?= h(App::asset('assets/upload.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/relevel.js')) ?>" defer></script>
