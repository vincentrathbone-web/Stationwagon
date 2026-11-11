<?php use function Stationwagon\{h}; use Stationwagon\{App, Streams};
/** @var array $rows @var int $edit @var int $confirm @var array $reserved @var string $base @var array $station @var string $csrf */
$origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$address = fn(array $s) => $s['main'] ? $origin . $base : $origin . rtrim($base, '/') . '/' . $s['slug'] . '/';
?>
<div class="vhead">
  <div>
    <h1>Streams</h1>
    <p><span id="streamsCount"><?= count($rows) ?> <?= count($rows) === 1 ? 'stream' : 'streams' ?></span>. A station can play more than one stream. Every stream has its own AutoDJ, weekly schedule, live shows and listener numbers; all of them play from the same Music library, playlists, ads and jingles, and share the sound settings and the people. The first stream is the station itself and keeps the addresses it has always had.</p>
  </div>
</div>

<div class="tablewrap lib" id="streamsWrap">
  <table class="lib streams" id="streamsTable">
    <thead><tr><th data-col="title">Stream</th><th data-col="address">Address</th><th data-col="onair">On air now</th><th class="num" data-col="listening">Listening</th><th class="num" data-col="blocks">Schedule blocks</th><th data-col="people">DJs</th><th data-col="acts"><span class="vh">Actions</span></th></tr></thead>
    <tbody id="streamsBody">
    <?php foreach ($rows as $s): $id = (int) $s['id']; $on = $s['on_air'] ?? null; ?>
      <tr id="s<?= $id ?>" class="clip">
        <td class="c-title" data-col="title"><div class="tcell"><b class="tt"><?= h($s['name']) ?></b><?= $s['main'] ? ' <span class="pill auto">Main</span>' : '' ?></div></td>
        <td class="c-artist" data-col="address"><a href="<?= h($address($s)) ?>" target="_blank" rel="noopener"><?= h($s['main'] ? '(the station’s own address)' : '…/' . $s['slug'] . '/') ?></a></td>
        <td class="c-artist" data-col="onair"><?= $on ? h(($on['type'] === 'live' ? 'LIVE · ' : '') . ($on['title'] ?? '')) : '—' ?></td>
        <td class="num" data-col="listening"><?= (int) $s['listening'] ?></td>
        <td class="num" data-col="blocks"><?= (int) $s['blocks'] ?></td>
        <td data-col="people"><?= $s['main'] ? 'Everyone with the main stream' : ((int) $s['people'] === 0 ? 'Managers only' : (int) $s['people'] . ' allowed') ?></td>
        <td class="acts" data-col="acts">
          <?php if ($confirm === $id && !$s['main']): ?>
            <span class="warn2">Remove this stream and its schedule?</span>
            <?= $post('manage/streams', 'Yes, remove', ['action' => 'delete', 'id' => $id], 'btn sm danger') ?>
            <a class="btn sm" href="<?= h(App::url('manage/streams')) ?>">Keep</a>
          <?php else: ?>
            <a class="btn sm" href="<?= h(App::url('manage') . '?stream=' . rawurlencode($s['main'] ? (string) $s['slug'] : (string) $s['slug'])) ?>">Overview</a>
            <a class="btn sm" href="<?= h(App::url('manage/schedule') . '?stream=' . rawurlencode((string) $s['slug'])) ?>">Schedule</a>
            <?php if (!$s['main']): ?>
              <a class="btn sm" href="<?= h(App::url('manage/streams') . '?edit=' . $id) ?>#s<?= $id ?>">Edit</a>
              <a class="btn sm" href="<?= h(App::url('manage/streams') . '?confirm=' . $id) ?>">Remove</a>
            <?php endif ?>
          <?php endif ?>
        </td>
      </tr>
      <?php if ($edit === $id && !$s['main']): ?>
        <tr class="editing"><td colspan="7">
          <form method="post" action="<?= h(App::url('manage/streams')) ?>" class="editrow adedit" id="streamEdit">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>">
            <label class="field"><span class="lbl">Name</span><input name="name" value="<?= h($s['name']) ?>" required maxlength="190"></label>
            <label class="field"><span class="lbl">Address name</span><input name="slug" value="<?= h($s['slug']) ?>" required maxlength="40" pattern="[a-z0-9][a-z0-9\-]*[a-z0-9]" autocapitalize="none"><span class="hint">lower case letters, digits and hyphens</span></label>
            <div class="editbtns">
              <button class="btn primary sm" type="submit">Save</button>
              <a class="btn sm" href="<?= h(App::url('manage/streams')) ?>">Cancel</a>
            </div>
          </form>
        </td></tr>
      <?php endif ?>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<section class="card embedcodes" id="embedCodes">
  <h2>Put a stream on a website</h2>
  <p class="muted2">Each stream has its own small player and its own Now playing card. Copy the code for the stream you want into the page.</p>
  <?php foreach ($rows as $s): $base2 = $s['main'] ? $address($s) : $address($s); $embedUrl = $s['main'] ? $origin . $base . 'embed' : $origin . rtrim($base, '/') . '/' . $s['slug'] . '/embed'; $nowUrl = $s['main'] ? $origin . $base . 'now' : $origin . rtrim($base, '/') . '/' . $s['slug'] . '/now'; ?>
    <details class="embedcode">
      <summary><b><?= h($s['name']) ?></b><?= $s['main'] ? ' <span class="pill auto">Main</span>' : '' ?></summary>
      <label class="field wide"><span class="lbl">The small player (any website)</span>
        <textarea readonly rows="2" data-copy><?= h('<iframe src="' . $embedUrl . '" width="100%" height="300" style="border:0" allow="autoplay" title="' . $s['name'] . '"></iframe>') ?></textarea></label>
      <button class="btn sm" type="button" data-copy-btn>Copy</button>
      <label class="field wide"><span class="lbl">The Now playing card (no sound; a click opens the stream’s player)</span>
        <textarea readonly rows="2" data-copy><?= h('<iframe src="' . $nowUrl . '" width="100%" height="150" style="border:0" title="Now playing on ' . $s['name'] . '"></iframe>') ?></textarea></label>
      <button class="btn sm" type="button" data-copy-btn>Copy</button>
    </details>
  <?php endforeach ?>
</section>
<script>
document.querySelectorAll("[data-copy-btn]").forEach((b) => b.addEventListener("click", async () => {
  const t = b.previousElementSibling.querySelector("textarea");
  try { await navigator.clipboard.writeText(t.value); b.textContent = "Copied"; } catch (_) { t.select(); b.textContent = "Press Ctrl+C"; }
  setTimeout(() => { b.textContent = "Copy"; }, 2000);
}));
</script>

<form class="card adsettings" method="post" action="<?= h(App::url('manage/streams')) ?>" id="streamAdd">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="create">
  <h2>Add a stream</h2>
  <div class="formrow">
    <label class="field"><span class="lbl">Name</span><input name="name" id="streamName" required maxlength="190" placeholder="Radio Gospel Hits"><span class="hint">what listeners see</span></label>
    <label class="field"><span class="lbl">Address name</span><input name="slug" id="streamSlug" maxlength="40" pattern="[a-z0-9][a-z0-9\-]*[a-z0-9]" autocapitalize="none" placeholder="gospel-hits"><span class="hint">the address ends with this; empty: made from the name</span></label>
    <button class="btn primary" type="submit">Add stream</button>
  </div>
  <p class="muted2">A new stream plays the whole library until you give it playlists on its schedule. Its player is at <b>…/<span id="streamPreview">name</span>/</b> under the station’s address. These names are taken: <?= h(implode(', ', $reserved)) ?>.</p>
</form>
<script>
(() => {
  const name = document.getElementById("streamName"), slug = document.getElementById("streamSlug"), preview = document.getElementById("streamPreview");
  const make = (s) => s.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 40);
  const show = () => { preview.textContent = slug.value.trim() || make(name.value) || "name"; };
  name.addEventListener("input", show); slug.addEventListener("input", show); show();
})();
</script>
