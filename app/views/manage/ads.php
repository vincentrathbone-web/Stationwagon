<?php use function Stationwagon\{h, duration}; use Stationwagon\{App, Ads};
/** @var array $ads @var array $adSettings @var string $q @var int $edit @var int $confirm @var float $now @var int $songs @var int $maxUpload @var array $prepQuality @var array $prep @var array $preset @var ?array $relevel @var array $station @var string $csrf */
$today = (new DateTimeImmutable('@' . (int) $now))->setTimezone(new DateTimeZone((string) ($station['timezone'] ?? 'UTC') ?: 'UTC'))->format('Y-m-d');
$running = count(array_filter($ads, fn(array $a) => Ads::status($a, $today)[1]));
$date = fn(?string $d) => $d !== null ? (new DateTimeImmutable($d))->format('j M Y') : '';
$tz = new DateTimeZone((string) ($station['timezone'] ?? 'UTC') ?: 'UTC');
$lufs = fn(float $v) => str_replace('-', '−', number_format($v, 1));
$ncols = 13;
?>
<div class="vhead">
  <div>
    <h1>Ads</h1>
    <p><span id="adsCount"><?= count($ads) ?> <?= count($ads) === 1 ? 'ad' : 'ads' ?>, <?= $running ?> running</span>. Ads are normal recordings that the AutoDJ plays between songs. Give each one how many times a day it should play, and the AutoDJ spreads those plays through the day: the ad that is furthest behind goes next. Listeners and the apps only see “Advertisement”.</p>
  </div>
</div>

<form class="card adsettings" method="post" action="<?= h(App::url('manage/ads')) ?>">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="settings">
  <h2>Ad breaks</h2>
  <div class="formrow">
    <label class="swrow" title="With this off, no ads play"><input class="sw" type="checkbox" name="enabled" value="1" id="adsOn"<?= $adSettings['enabled'] ? ' checked' : '' ?>> Play ads</label>
    <label class="field"><span class="lbl">Ads after every</span><input type="number" name="every" id="adsEvery" min="1" max="20" step="1" value="<?= (int) $adSettings['every'] ?>" required><span class="hint">songs (1 to 20)</span></label>
    <label class="field"><span class="lbl">Ads in a break</span><input type="number" name="per_break" id="adsPer" min="1" max="3" step="1" value="<?= (int) $adSettings['per_break'] ?>" required><span class="hint">up to this many (1 to 3)</span></label>
    <label class="field"><span class="lbl">At least</span><input type="number" name="gap_minutes" id="adsGap" min="0" max="120" step="1" value="<?= (int) $adSettings['gap_minutes'] ?>" required><span class="hint">minutes between breaks (0 for none)</span></label>
    <button class="btn primary" type="submit">Save</button>
  </div>
  <p class="muted2">After every <?= (int) $adSettings['every'] ?> song<?= $adSettings['every'] === 1 ? '' : 's' ?> the AutoDJ plays <?= $adSettings['per_break'] === 1 ? 'the ad that is due' : 'up to ' . (int) $adSettings['per_break'] . ' of the ads that are due' ?>. If none is due it plays songs and tries again after the next one. Ads never play during a live show, and the first ad after a live show comes after <?= (int) $adSettings['every'] ?> songs.<?= $adSettings['enabled'] ? '' : ' <b>Ads are off now.</b>' ?><?= $songs === 0 ? ' There are no songs in the Music library yet, and the AutoDJ needs songs to play ads between.' : '' ?></p>
</form>

<?php
$dropRoute = 'manage/ads';
$dropNoun = 'ad';
$dropIntro = 'Drop ad recordings here, or choose them: MP3, WAV, FLAC, M4A (AAC or ALAC), AAC, Ogg, Opus or WMA. They are prepared and levelled like songs, with the choices below; there is no album art or copyright window.';
require __DIR__ . '/_drop.php';

// the list, laid out like the Music library (round 72): the same toolbar, compact table, symbols for the badges, columns to choose
$clipRoute = 'manage/ads';
$clipNoun = 'ad';
$clipStore = 'ads';
$cols = ['advertiser' => 'Advertiser', 'length' => 'Length', 'badges' => 'Badges', 'added' => 'Added', 'loudness' => 'Loudness', 'plays' => 'Plays a day', 'today' => 'Today', 'when' => 'When', 'dates' => 'Dates'];
$hiddenCols = ['added', 'loudness', 'dates'];
$statusWords = ['Running', 'Paused'];
$bulkWords = ['Pause', 'Resume'];
require __DIR__ . '/_clips.php';
?>

<div class="tablewrap lib" id="adsWrap">
  <table class="lib ads" id="adsTable" data-hidden="<?= h(implode(' ', $hiddenCols)) ?>">
    <thead><tr>
      <th class="c-sel" data-col="sel"><input type="checkbox" id="selAll" aria-label="Select every ad shown"></th>
      <th data-col="title" aria-sort="ascending"><button type="button" class="sortb" data-key="title">Ad</button></th>
      <?php foreach ($cols as $key => $label): $num = in_array($key, ['length', 'loudness', 'plays'], true); ?>
        <th data-col="<?= h($key) ?>"<?= $num ? ' class="num"' : '' ?>><?php if ($key === 'badges'): ?><?= h($label) ?><?php else: ?><button type="button" class="sortb" data-key="<?= h($key) ?>"<?= $num ? ' data-type="num"' : '' ?>><?= h($label) ?></button><?php endif ?></th>
      <?php endforeach ?>
      <th data-col="status"><button type="button" class="sortb" data-key="status" data-type="num">Status</button></th>
      <th data-col="acts"><span class="vh">Actions</span></th>
    </tr></thead>
    <tbody id="adsBody">
    <?php if (!$ads): ?>
      <tr class="empty"><td colspan="<?= $ncols ?>" class="muted2"><?= $q !== '' ? 'No ads match “' . h($q) . '”.' : 'No ads yet. Add some above, then say how often each one plays.' ?></td></tr>
    <?php endif ?>
    <?php foreach ($ads as $a): $id = (int) $a['track_id']; [$status, $live] = Ads::status($a, $today); $target = (int) $a['daily_target'];
      $added = ''; try { $added = (new DateTimeImmutable((string) $a['uploaded_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('j M Y'); } catch (Throwable) {} ?>
      <tr id="a<?= $id ?>" class="clip ad<?= $live ? '' : ' off' ?>" data-state="<?= $live ? 'on' : 'off' ?>">
        <td class="c-sel" data-col="sel"><input type="checkbox" class="sel" value="<?= $id ?>" aria-label="Select <?= h($a['title']) ?>"></td>
        <td class="c-title" data-col="title"><div class="tcell"><b class="tt"><?= h($a['title']) ?></b></div></td>
        <td class="c-artist c-advertiser" data-col="advertiser"><?= h((string) ($a['advertiser'] ?? '')) ?></td>
        <td class="num" data-col="length" data-sort="<?= h((string) (float) $a['duration_s']) ?>"><?= h(duration((float) $a['duration_s'])) ?></td>
        <td class="c-badges" data-col="badges"><?= $clipBadges($a) ?></td>
        <td class="num" data-col="added" data-sort="<?= h((string) $a['uploaded_at']) ?>"><?= h($added) ?></td>
        <td class="num" data-col="loudness" data-sort="<?= $a['loudness_lufs'] !== null ? h((string) (float) $a['loudness_lufs']) : '' ?>"><?= $a['loudness_lufs'] !== null ? h($lufs((float) $a['loudness_lufs'])) : '—' ?></td>
        <td class="num c-plays" data-col="plays" data-sort="<?= $target ?>"><?= $target ?></td>
        <td class="today" data-col="today" data-sort="<?= (int) $a['aired_today'] ?>"><?= $target === 0 ? '—' : (int) $a['aired_today'] . ' played' . ((int) $a['planned_today'] > 0 ? ', ' . (int) $a['planned_today'] . ' planned' : '') . ' of ' . $target ?></td>
        <td class="when" data-col="when"><?= h(Ads::describe($a)) ?></td>
        <td class="dates" data-col="dates"><?= $a['start_date'] !== null || $a['end_date'] !== null ? h(($a['start_date'] !== null ? $date($a['start_date']) : 'Any time') . ' – ' . ($a['end_date'] !== null ? $date($a['end_date']) : 'no end')) : 'No end' ?></td>
        <td class="c-status" data-col="status" data-sort="<?= $live ? 0 : 1 ?>"><span class="pill<?= $live ? ' auto' : '' ?>"><?= h($status) ?></span></td>
        <td class="acts" data-col="acts">
          <?php if ($confirm === $id): ?>
            <span class="warn2">Remove this ad?</span>
            <?= $post('manage/ads', 'Yes, remove', ['action' => 'delete', 'id' => $id], 'btn sm danger') ?>
            <a class="btn sm" href="<?= h(App::url('manage/ads')) ?>">Keep</a>
          <?php else: ?>
            <a class="btn sm" href="<?= h(App::url('manage/ads') . '?edit=' . $id) ?>#a<?= $id ?>">Edit</a>
            <?= $dl($id, 'ad') ?>
            <a class="btn sm" href="<?= h(App::url('manage/ads') . '?confirm=' . $id) ?>">Remove</a>
          <?php endif ?>
        </td>
      </tr>
      <?php if ($edit === $id):
        $days = array_map(fn($bit) => ((int) $a['days'] & $bit) !== 0, array_keys(Ads::DAY_NAMES)); ?>
        <tr class="editing"><td colspan="<?= $ncols ?>">
          <form method="post" action="<?= h(App::url('manage/ads')) ?>" class="editrow adedit" id="adEdit">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>">
            <label class="field"><span class="lbl">Title</span><input name="title" value="<?= h($a['title']) ?>" required maxlength="255"></label>
            <label class="field"><span class="lbl">Advertiser</span><input name="advertiser" value="<?= h((string) ($a['advertiser'] ?? '')) ?>" maxlength="190"></label>
            <label class="field"><span class="lbl">Plays a day</span><input type="number" name="daily_target" min="0" max="500" step="1" value="<?= $target ?>" required><span class="hint">0 pauses the ad</span></label>
            <label class="field"><span class="lbl">Start date</span><input type="date" name="start_date" value="<?= h((string) ($a['start_date'] ?? '')) ?>"><span class="hint">empty: from now</span></label>
            <label class="field"><span class="lbl">End date</span><input type="date" name="end_date" value="<?= h((string) ($a['end_date'] ?? '')) ?>"><span class="hint">empty: no end</span></label>
            <label class="field"><span class="lbl">From</span><input type="time" name="window_start" value="<?= h((string) ($a['window_start'] ?? '')) ?>"><span class="hint">empty (with Until): all day</span></label>
            <label class="field"><span class="lbl">Until</span><input type="time" name="window_end" value="<?= h((string) ($a['window_end'] ?? '')) ?>"><span class="hint">station time</span></label>
            <fieldset class="field daypick"><legend class="lbl">Days</legend>
              <?php foreach (array_values(Ads::DAY_NAMES) as $i => $name): ?><label class="swrow"><input class="sw" type="checkbox" name="days[]" value="<?= $i + 1 ?>"<?= $days[$i] ? ' checked' : '' ?>> <?= h($name) ?></label><?php endforeach ?>
            </fieldset>
            <div class="field">
              <label class="swrow"><input class="sw" type="checkbox" name="active" value="1"<?= (int) $a['active'] === 1 ? ' checked' : '' ?>> Active</label>
              <label class="swrow" title="When two ads are equally due, this one goes first"><input class="sw" type="checkbox" name="priority" value="1"<?= (int) $a['priority'] === 1 ? ' checked' : '' ?>> Plays first when two are due</label>
            </div>
            <label class="field wide"><span class="lbl">Notes</span><textarea name="notes" rows="2" maxlength="2000"><?= h((string) ($a['notes'] ?? '')) ?></textarea></label>
            <div class="editbtns">
              <button class="btn primary sm" type="submit">Save</button>
              <a class="btn sm" href="<?= h(App::url('manage/ads')) ?>">Cancel</a>
            </div>
          </form>
        </td></tr>
      <?php endif ?>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
<script src="<?= h(App::asset('assets/cliplist.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/prep-core.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/prep.js')) ?>" data-kbps="<?= $prepQuality['kbps'] ?>" data-rate="<?= $prepQuality['rate'] ?>" data-precision="<?= $prepQuality['precision'] ?>" data-dither="<?= $prepQuality['dither'] ? 1 : 0 ?>" data-quality="<?= $prepQuality['q'] ?>" data-worker="<?= h(App::asset('assets/prep-worker.js', ['assets/prep-core.js', 'assets/master-core.js', 'assets/prep-run.js', 'assets/vendor/lame-3.100.js', 'assets/vendor/ffmpeg-decode-7.1.js'])) ?>" defer></script>
<script src="<?= h(App::asset('assets/upload.js')) ?>" defer></script>
