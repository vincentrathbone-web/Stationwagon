<?php use function Stationwagon\{h, clock}; use Stationwagon\App;
/** @var array $episode @var int $kbps @var array $station @var string $csrf */
?>
<div class="ed" id="editor" data-id="<?= (int) $episode['id'] ?>" data-base="<?= h(App::url('')) ?>" data-csrf="<?= h($csrf) ?>" data-kbps="<?= (int) $kbps ?>"
     data-encoder="<?= h(App::asset('assets/studio-encoder.js')) ?>">
  <div class="vhead">
    <div>
      <p class="crumb"><a href="<?= h(App::url('manage/podcast')) ?>">Podcast</a> › Episode</p>
      <h1 id="edTitle"><?= h($episode['title']) ?></h1>
      <p>Recorded <?= h(clock((float) $episode['recorded_at'], $station['timezone'], 'l j F Y, H:i')) ?>. Click a clip and press Delete to cut it out; long silences left behind shrink to a second. Then mix down and publish.</p>
    </div>
  </div>

  <section class="card edcard">
    <div class="edbar">
      <button class="btn sm" type="button" id="edZoomOut" title="Zoom out (Ctrl + wheel)" aria-label="Zoom out">−</button>
      <button class="btn sm" type="button" id="edZoomIn" title="Zoom in (Ctrl + wheel)" aria-label="Zoom in">+</button>
      <button class="btn sm" type="button" id="edFit">Whole show</button>
      <span class="edsep"></span>
      <button class="btn sm danger" type="button" id="edDelete" disabled>Delete clip</button>
      <button class="btn sm" type="button" id="edUndo" disabled>Undo</button>
      <span class="edinfo" id="edInfo" aria-live="polite"></span>
    </div>
    <div class="edscroll" id="edScroll" tabindex="0" aria-label="The episode’s tracks: click a clip to select it, Delete to cut it">
      <div class="edspace" id="edSpace"></div>
      <canvas id="edCv"></canvas>
    </div>
    <p class="edmsg" id="edMsg" aria-live="polite">Loading the tracks…</p>
  </section>

  <section class="card edcard">
    <h2>Mixdown</h2>
    <p class="muted" id="edMixNote">Mixdown flattens the tracks into one MP3, without the clips you cut.</p>
    <div class="edmix">
      <button class="btn primary" type="button" id="edMix" disabled>Mix down</button>
      <div class="edprog" id="edProg" hidden><div id="edProgBar"></div></div>
      <span class="edinfo" id="edMixInfo"></span>
    </div>
    <audio id="edAudio" controls preload="none" hidden></audio>
  </section>

  <section class="card edcard">
    <h2>Publish</h2>
    <div class="grid">
      <label class="field"><span class="lbl">Title</span><input id="edName" maxlength="190" value="<?= h($episode['title']) ?>"></label>
    </div>
    <label class="field"><span class="lbl">Description</span><textarea id="edDesc" rows="4" maxlength="4000"><?= h((string) $episode['description']) ?></textarea></label>
    <div class="podfoot">
      <span class="edinfo" id="edPubInfo"></span>
      <button class="btn sm" type="button" id="edHide" hidden>Hide</button>
      <button class="btn sm" type="button" id="edSave">Save</button>
      <button class="btn primary" type="button" id="edPublish" disabled>Publish</button>
    </div>
  </section>
</div>
<script src="<?= h(App::asset('assets/podcast-core.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/podcast-editor.js')) ?>" defer></script>
