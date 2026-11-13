<?php use function Stationwagon\{h, duration}; use Stationwagon\{App, Jingles};
/** @var array $jingles @var array $jingleSettings @var string $q @var int $edit @var int $confirm @var float $now @var int $songs @var int $maxUpload @var array $prepQuality @var array $prep @var array $preset @var ?array $relevel @var array $station @var string $csrf */
$on = count(array_filter($jingles, fn(array $j) => (int) $j['active'] === 1));
$tz = new DateTimeZone((string) ($station['timezone'] ?? 'UTC') ?: 'UTC');
$lufs = fn(float $v) => str_replace('-', '−', number_format($v, 1));
$ncols = 10;
?>
<div class="vhead">
  <div>
    <h1>Jingles</h1>
    <p><span id="jinglesCount"><?= count($jingles) ?> <?= count($jingles) === 1 ? 'jingle' : 'jingles' ?>, <?= $on ?> on</span>. Jingles are tiny clips that announce the station between songs. The AutoDJ plays one after every few songs, always the one played longest ago, so several jingles take turns. Listeners and the apps see the station’s name.</p>
  </div>
</div>

<form class="card adsettings" method="post" action="<?= h(App::url('manage/jingles')) ?>">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="settings">
  <h2>Jingles between songs</h2>
  <div class="formrow">
    <label class="swrow" title="With this off, no jingles play"><input class="sw" type="checkbox" name="enabled" value="1" id="jinglesOn"<?= $jingleSettings['enabled'] ? ' checked' : '' ?>> Play jingles</label>
    <label class="field"><span class="lbl">A jingle after every</span><input type="number" name="every" id="jinglesEvery" min="1" max="20" step="1" value="<?= (int) $jingleSettings['every'] ?>" required><span class="hint">songs (1 to 20)</span></label>
    <button class="btn primary" type="submit">Save</button>
  </div>
  <p class="muted2">After every <?= (int) $jingleSettings['every'] ?> song<?= $jingleSettings['every'] === 1 ? '' : 's' ?> the AutoDJ plays a jingle between two songs. A jingle never comes straight after an ad, another jingle or a live show, and an ad break does not count as a song. It blends with the songs around it over <?= h(rtrim(rtrim(number_format((float) $jingleSettings['crossfade'], 1), '0'), '.')) ?> second<?= (float) $jingleSettings['crossfade'] === 1.0 ? '' : 's' ?> (<?php if (Stationwagon\Auth::allows($me, 'sound')): ?><a href="<?= h(App::url('manage/sound')) ?>#g-transitions">change this in Sound &amp; safety</a><?php else: ?>set in Sound &amp; safety<?php endif ?>).<?= $jingleSettings['enabled'] ? '' : ' <b>Jingles are off now.</b>' ?><?= $on === 0 && $jingleSettings['enabled'] ? ' No jingle is on yet, so nothing plays until you add one.' : '' ?><?= $songs === 0 ? ' There are no songs in the Music library yet, and the AutoDJ needs songs to play jingles between.' : '' ?></p>
</form>

<?php
$dropRoute = 'manage/jingles';
$dropNoun = 'jingle';
$dropIntro = 'Drop jingle recordings here, or choose them: MP3, WAV, FLAC, M4A (AAC or ALAC), AAC, Ogg, Opus or WMA. They are prepared and levelled like songs, with the choices below; there is no album art or copyright window.';
require __DIR__ . '/_drop.php';

// the list, laid out like the Music library (round 72): the same toolbar, compact table, symbols for the badges, columns to choose
$clipRoute = 'manage/jingles';
$clipNoun = 'jingle';
$clipStore = 'jingles';
$cols = ['notes' => 'Notes', 'length' => 'Length', 'badges' => 'Badges', 'added' => 'Added', 'loudness' => 'Loudness', 'today' => 'Today'];
$hiddenCols = ['added', 'loudness'];
$statusWords = ['On', 'Off'];
$bulkWords = ['Turn off', 'Turn on'];
require __DIR__ . '/_clips.php';
?>

<div class="tablewrap lib" id="jinglesWrap">
  <table class="lib jingles" id="jinglesTable" data-hidden="<?= h(implode(' ', $hiddenCols)) ?>">
    <thead><tr>
      <th class="c-sel" data-col="sel"><input type="checkbox" id="selAll" aria-label="Select every jingle shown"></th>
      <th data-col="title" aria-sort="ascending"><button type="button" class="sortb" data-key="title">Jingle</button></th>
      <?php foreach ($cols as $key => $label): $num = in_array($key, ['length', 'loudness'], true); ?>
        <th data-col="<?= h($key) ?>"<?= $num ? ' class="num"' : '' ?>><?php if ($key === 'badges'): ?><?= h($label) ?><?php else: ?><button type="button" class="sortb" data-key="<?= h($key) ?>"<?= $num ? ' data-type="num"' : '' ?>><?= h($label) ?></button><?php endif ?></th>
      <?php endforeach ?>
      <th data-col="status"><button type="button" class="sortb" data-key="status" data-type="num">Status</button></th>
      <th data-col="acts"><span class="vh">Actions</span></th>
    </tr></thead>
    <tbody id="jinglesBody">
    <?php if (!$jingles): ?>
      <tr class="empty"><td colspan="<?= $ncols ?>" class="muted2"><?= $q !== '' ? 'No jingles match “' . h($q) . '”.' : 'No jingles yet. Add some above.' ?></td></tr>
    <?php endif ?>
    <?php foreach ($jingles as $j): $id = (int) $j['track_id']; $live = (int) $j['active'] === 1;
      $added = ''; try { $added = (new DateTimeImmutable((string) $j['uploaded_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('j M Y'); } catch (Throwable) {} ?>
      <tr id="j<?= $id ?>" class="clip ad<?= $live ? '' : ' off' ?>" data-state="<?= $live ? 'on' : 'off' ?>">
        <td class="c-sel" data-col="sel"><input type="checkbox" class="sel" value="<?= $id ?>" aria-label="Select <?= h($j['title']) ?>"></td>
        <td class="c-title" data-col="title"><div class="tcell"><b class="tt"><?= h($j['title']) ?></b></div></td>
        <td class="c-artist c-advertiser" data-col="notes"><?= h((string) ($j['notes'] ?? '')) ?></td>
        <td class="num" data-col="length" data-sort="<?= h((string) (float) $j['duration_s']) ?>"><?= h(duration((float) $j['duration_s'])) ?></td>
        <td class="c-badges" data-col="badges"><?= $clipBadges($j) ?></td>
        <td class="num" data-col="added" data-sort="<?= h((string) $j['uploaded_at']) ?>"><?= h($added) ?></td>
        <td class="num" data-col="loudness" data-sort="<?= $j['loudness_lufs'] !== null ? h((string) (float) $j['loudness_lufs']) : '' ?>"><?= $j['loudness_lufs'] !== null ? h($lufs((float) $j['loudness_lufs'])) : '—' ?></td>
        <td class="today" data-col="today" data-sort="<?= (int) $j['aired_today'] ?>"><?= (int) $j['aired_today'] . ' played' . ((int) $j['planned_today'] > 0 ? ', ' . (int) $j['planned_today'] . ' planned' : '') ?></td>
        <td class="c-status" data-col="status" data-sort="<?= $live ? 0 : 1 ?>"><span class="pill<?= $live ? ' auto' : '' ?>"><?= $live ? 'On' : 'Off' ?></span></td>
        <td class="acts" data-col="acts">
          <?php if ($confirm === $id): ?>
            <span class="warn2">Remove this jingle?</span>
            <?= $post('manage/jingles', 'Yes, remove', ['action' => 'delete', 'id' => $id], 'btn sm danger') ?>
            <a class="btn sm" href="<?= h(App::url('manage/jingles')) ?>">Keep</a>
          <?php else: ?>
            <a class="btn sm" href="<?= h(App::url('manage/jingles') . '?edit=' . $id) ?>#j<?= $id ?>">Edit</a>
            <?= $dl($id, 'jingle') ?>
            <a class="btn sm" href="<?= h(App::url('manage/jingles') . '?confirm=' . $id) ?>">Remove</a>
          <?php endif ?>
        </td>
      </tr>
      <?php if ($edit === $id): ?>
        <tr class="editing"><td colspan="<?= $ncols ?>">
          <form method="post" action="<?= h(App::url('manage/jingles')) ?>" class="editrow adedit" id="jingleEdit">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>">
            <label class="field"><span class="lbl">Title</span><input name="title" value="<?= h($j['title']) ?>" required maxlength="255"></label>
            <div class="field">
              <label class="swrow"><input class="sw" type="checkbox" name="active" value="1"<?= $live ? ' checked' : '' ?>> On</label>
            </div>
            <label class="field wide"><span class="lbl">Notes</span><textarea name="notes" rows="2" maxlength="2000"><?= h((string) ($j['notes'] ?? '')) ?></textarea></label>
            <div class="editbtns">
              <button class="btn primary sm" type="submit">Save</button>
              <a class="btn sm" href="<?= h(App::url('manage/jingles')) ?>">Cancel</a>
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
