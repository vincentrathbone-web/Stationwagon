<?php use function Stationwagon\{h, duration, megabytes}; use Stationwagon\App;
/** @var array $tracks @var string $q @var int $total @var int $bytes @var int $confirm @var int $edit @var int $maxUpload @var int $prepKbps @var string $csrf */
?>
<div class="vhead">
  <div>
    <h1>Music library</h1>
    <p><?= $total ?> songs · <?= h(megabytes($bytes)) ?>. New songs join the AutoDJ straight away; playlists and the schedule decide when they play.</p>
  </div>
</div>

<form class="drop" id="drop" method="post" enctype="multipart/form-data" action="<?= h(App::url('manage/library')) ?>" data-max="<?= (int) $maxUpload ?>">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="upload">
  <div><b>Add songs</b><br><span class="muted2">Drop MP3 files here, or choose them. Your browser first levels each song to the same loudness (−16 LUFS) and trims silence at both ends. Up to <?= h(megabytes($maxUpload)) ?> per upload; the same song twice is only stored once.</span></div>
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
          <td><b><?= h($t['title']) ?></b><?php if ($t['loudness_lufs'] !== null): ?> <span class="pill auto" title="Levelled to <?= h(str_replace('-', '−', number_format((float) $t['loudness_lufs'], 1))) ?> LUFS when uploaded">Levelled</span><?php endif ?><?php if ($t['album']): ?><span class="sub"><?= h($t['album']) ?></span><?php endif ?></td>
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
<script src="<?= h(App::asset('assets/prep.js')) ?>" data-kbps="<?= (int) $prepKbps ?>" data-worker="<?= h(App::asset('assets/prep-worker.js', ['assets/prep-core.js'])) ?>" defer></script>
<script src="<?= h(App::asset('assets/upload.js')) ?>" defer></script>
