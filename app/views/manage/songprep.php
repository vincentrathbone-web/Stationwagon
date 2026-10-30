<?php use function Stationwagon\h; use Stationwagon\App;
/** @var array $tracks @var array $presets @var int $defaultId @var int $prepKbps @var array $prep @var int $song @var string $csrf */
[, $prepLevel] = $prep;
$data = [
    'tracks' => array_map(fn($t) => ['id' => (int) $t['id'], 'title' => $t['title'], 'artist' => $t['artist'], 'file' => $t['file'], 'original' => $t['original'],
        'mastered' => (bool) $t['mastered'], 'preset' => $t['preset']], $tracks),
    'presets' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'settings' => (object) $p['settings']], $presets),
    'defaultId' => $defaultId,
    'level' => $prepLevel,
    'song' => $song,
    'csrf' => $csrf,
    'postUrl' => App::url('manage/songprep'),
    'origUrl' => App::url('manage/songprep/original'),
    'mediaBase' => App::url('storage/media/'),
    'worker' => App::asset('assets/songprep-worker.js', ['assets/prep-core.js', 'assets/master-core.js']),
];
?>
<div class="songprep" id="songprep">
  <div class="vhead">
    <div>
      <h1>Song prep</h1>
      <p>Try mastering settings on any song and hear the whole of it, original against processed, both at the level the station plays them<?= $prepLevel ? ' (−16 LUFS)' : '' ?>. Save the settings as a preset and pick the one new uploads get.</p>
    </div>
  </div>

  <section class="card sppick">
    <label class="field"><span class="lbl">Song</span><select id="spSong"></select></label>
    <label class="field"><span class="lbl">Preset</span><select id="spPreset"></select></label>
    <label class="field"><span class="lbl">Name</span><input id="spName" maxlength="60" placeholder="A name for these settings"></label>
    <div class="spbtns">
      <button class="btn" type="button" id="spSave" title="Save the changes to this preset">Save</button>
      <button class="btn" type="button" id="spSaveAs">Save as new</button>
      <button class="btn" type="button" id="spDelete">Remove</button>
      <button class="btn" type="button" id="spDefault">Use for new uploads</button>
      <button class="btn primary" type="button" id="spApply" title="Prepare this song again from its original with these settings">Apply to this song</button>
    </div>
    <p class="spdirty" id="spDirty" hidden>Changed, not saved yet.</p>
    <p class="muted2" id="spSource"></p>
    <p class="spmsg" id="spMsg" aria-live="polite"></p>
  </section>

  <section class="card spplayer">
    <div class="sptransport">
      <button class="btn primary" type="button" id="spPlay">Play</button>
      <div class="spab" role="group" aria-label="Hear">
        <button class="btn" type="button" id="spHearO" aria-pressed="false" title="The song as uploaded (A key switches)">Original</button>
        <button class="btn" type="button" id="spHearP" aria-pressed="true" title="With these settings (A key switches)">Processed</button>
      </div>
      <span class="sptime"><b id="spAt">0:00</b> / <span id="spLen">0:00</span></span>
      <span class="muted2" id="spRender"></span>
      <label class="spvol">Volume <input type="range" id="spVol" min="0" max="1" step="0.01" value="0.8"></label>
    </div>
    <canvas class="spov" id="spOverview" aria-label="The whole song: original in grey, processed in colour. Click to play from there."></canvas>
    <div class="spstriphead">
      <span>Around the playhead, at the level the station plays it · now <b>Original <span id="spNowO">–</span></b> · <b class="spacc">Processed <span id="spNowP">–</span></b> LUFS (3 s)</span>
      <label>Seconds shown <select id="spZoom"><option value="4">4</option><option value="8" selected>8</option><option value="16">16</option><option value="30">30</option></select></label>
    </div>
    <canvas class="spstrip" id="spStrip" aria-label="The seconds around the playhead, enlarged: original in grey, processed in colour"></canvas>
    <div class="spread">
      <table class="spnums">
        <thead><tr><th></th><th>Loudness</th><th>Loudness range</th><th>Peak</th><th>Level on import</th></tr></thead>
        <tbody>
          <tr><th>Original</th><td><span id="spLufsO">–</span> LUFS</td><td><span id="spLraO">–</span> LU</td><td><span id="spPeakO">–</span> dBFS</td><td><span id="spGainO">–</span> dB</td></tr>
          <tr><th class="spacc">Processed</th><td><span id="spLufsP">–</span> LUFS</td><td><span id="spLraP">–</span> LU</td><td><span id="spPeakP">–</span> dBFS</td><td><span id="spGainP">–</span> dB</td></tr>
        </tbody>
      </table>
      <div class="spmeterbox"><b>Gain reduction now</b><canvas class="spmeters" id="spMeters" aria-label="How many dB each band and limiter is turning the sound down"></canvas></div>
    </div>
  </section>

  <div class="spcols">
    <section class="card speq">
      <h2><label class="swrow"><input class="sw" type="checkbox" id="spEqOn"> EQ</label></h2>
      <p class="muted2">Drag a point: left and right is the frequency, up and down the gain; the mouse wheel on a bell changes its width. The EQ comes first, before the compressor.</p>
      <label class="swrow"><input class="sw" type="checkbox" id="spHpOn"> High-pass (cuts rumble below its frequency)</label>
      <canvas class="speqc" id="spEq" aria-label="The EQ curve, 20 Hz to 20 kHz"></canvas>
      <div class="speqfields" id="spEqFields"></div>
    </section>
    <section class="card spdyn">
      <h2><label class="swrow"><input class="sw" type="checkbox" id="spCompOn"> Dynamics</label></h2>
      <div class="spsimple" id="spSimple"></div>
      <details class="spadv">
        <summary>All the settings</summary>
        <p class="muted2">The simple controls above set these; changing one here keeps the rest.</p>
        <label class="swrow"><input class="sw" type="checkbox" id="spFinishOn"> Finish (brightness, exciter, width, maximizer and final limiter)</label>
        <div id="spAdvanced"></div>
      </details>
    </section>
  </div>
</div>
<script type="application/json" id="songprepData"><?= json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= h(App::asset('assets/prep-core.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/master-core.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/prep.js')) ?>" data-kbps="<?= (int) $prepKbps ?>" data-worker="<?= h(App::asset('assets/prep-worker.js', ['assets/prep-core.js', 'assets/master-core.js'])) ?>" defer></script>
<script src="<?= h(App::asset('assets/songprep.js')) ?>" defer></script>
