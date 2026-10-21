<?php use function Stationwagon\{h, clock}; use Stationwagon\{App, Podcast, PodcastPage};
/** @var array $episodes @var array $details @var bool $isManager @var array $station @var string $csrf */
$tz = $station['timezone'];
$len = function (float $s): string { $s = (int) round($s); return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60); };
$states = ['draft' => ['Draft', 'pill'], 'published' => ['Published', 'pill auto'], 'hidden' => ['Hidden', 'pill']];
$feed = PodcastPage::base() . 'podcast/feed';
?>
<div class="vhead vhead-row">
  <div>
    <h1>Podcast</h1>
    <p>Every live show becomes a draft episode. Open one to cut out what you don’t want, mix it down and publish it to the podcast page and to Apple Podcasts and Spotify. <?= $isManager ? 'You see every episode.' : 'You see your own shows.' ?></p>
  </div>
  <a class="btn sm" href="<?= h(App::url('podcast')) ?>">Open the podcast page</a>
</div>

<?php if (!$episodes): ?>
  <p class="card muted" id="noEpisodes">No episodes yet. Your next live show (of two minutes or more) appears here as a draft when it ends.</p>
<?php else: ?>
<div class="tablewrap">
  <table id="episodes">
    <thead><tr><th>Episode</th><th>Recorded</th><th class="num">Length</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($episodes as $ep): [$name, $cls] = $states[$ep['status']] ?? [$ep['status'], 'pill']; ?>
      <tr data-episode="<?= (int) $ep['id'] ?>">
        <td><b><?= h($ep['title']) ?></b><span class="sub"><?= h($ep['source'] === 'device' ? 'Recorded in the studio: music and mic' : 'As it aired: one track') ?></span></td>
        <td><?= h(clock((float) $ep['recorded_at'], $tz, 'j M Y, H:i')) ?></td>
        <td class="num"><?= h($ep['audio_file'] ? $len((float) $ep['audio_seconds']) : $len((float) $ep['raw_seconds']) . ' raw') ?></td>
        <td><span class="<?= h($cls) ?>"><?= h($name) ?></span></td>
        <td class="acts"><a class="btn sm" href="<?= h(App::url('manage/podcast/' . (int) $ep['id'])) ?>">Edit</a></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
<?php endif ?>

<?php if ($isManager): ?>
<form class="card newform podform" method="post" action="<?= h(App::url('manage/podcast')) ?>" enctype="multipart/form-data">
  <h2>The podcast</h2>
  <p class="muted">What podcast apps show. Give Apple Podcasts and Spotify this feed address once; new episodes reach them by themselves.</p>
  <div class="feedrow"><code id="feedUrl"><?= h($feed) ?></code> <button class="btn sm" type="button" id="copyFeed">Copy</button></div>
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <div class="grid">
    <label class="field"><span class="lbl">Title</span><input name="title" maxlength="190" value="<?= h($details['title']) ?>" required></label>
    <label class="field"><span class="lbl">Author</span><input name="author" maxlength="190" value="<?= h($details['author']) ?>"></label>
    <label class="field"><span class="lbl">Owner’s email</span><input type="email" name="email" maxlength="190" value="<?= h($details['email']) ?>"><span class="hint">Apple sends feed notices here. Not shown to listeners.</span></label>
    <label class="field"><span class="lbl">Language</span><input name="language" maxlength="10" value="<?= h($details['language']) ?>" pattern="[a-z]{2}(-[A-Za-z]{2})?" required><span class="hint">A code like en or af.</span></label>
    <label class="field"><span class="lbl">Category</span><select name="category"><?php foreach (Podcast::CATEGORIES as $k => $label): ?><option value="<?= h($k) ?>"<?= $k === $details['category'] ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach ?></select></label>
    <label class="field"><span class="lbl">Cover image</span><input type="file" name="cover" accept="image/jpeg,image/png"><span class="hint">Square JPEG or PNG, 1400 to 3000 pixels.</span></label>
  </div>
  <label class="field"><span class="lbl">Description</span><textarea name="description" rows="3" maxlength="4000"><?= h($details['description']) ?></textarea></label>
  <div class="podfoot">
    <label class="swrow"><input type="checkbox" name="explicit" value="1"<?= $details['explicit'] ? ' checked' : '' ?>> Explicit content</label>
    <?php if ($details['cover']): ?><img class="podcover" src="<?= h(App::url($details['cover'])) ?>" alt="The podcast’s cover" width="64" height="64"><?php endif ?>
    <button class="btn primary" type="submit">Save</button>
  </div>
</form>
<script>
document.getElementById("copyFeed").onclick = async (e) => {
  try { await navigator.clipboard.writeText(document.getElementById("feedUrl").textContent); e.target.textContent = "Copied"; }
  catch (_) { getSelection().selectAllChildren(document.getElementById("feedUrl")); }
};
</script>
<?php endif ?>
