<?php use function Stationwagon\h; use Stationwagon\App;
/** @var array $station @var array $me @var string $csrf @var int $delay */
$deck = fn(string $k): string => '
  <section class="st-win st-deck" id="deck' . $k . '" aria-label="Deck ' . $k . '">
    <div class="st-titlebar"><span><i class="st-dot ' . strtolower($k) . '" aria-hidden="true"></i>Deck ' . $k . '</span><span class="st-state" id="st' . $k . '">Empty</span></div>
    <div class="st-body">
      <div class="st-lcd st-info">
        <span class="t" id="t' . $k . '">Nothing loaded</span>
        <span class="rem" id="r' . $k . '">-0:00</span>
        <span class="a" id="a' . $k . '">' . ($k === 'A' ? 'Picks up the AutoDJ song when you go live' : 'Load a song from the running order or the library') . '</span>
        <span class="src" id="s' . $k . '"></span>
      </div>
      <canvas class="st-wave" id="w' . $k . '" height="64" aria-label="Waveform of deck ' . $k . '. Click to move the play position"></canvas>
      <div class="st-ctl">
        <button class="btn sm cue" type="button" id="c' . $k . '" aria-pressed="false" title="Hear this deck in your headphones only" disabled>Cue</button>
        <button class="btn sm" type="button" id="p' . $k . '" aria-pressed="false" disabled><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 2l10 6-10 6z"/></svg><span>Play</span></button>
        <button class="btn sm" type="button" id="x' . $k . '" disabled><svg viewBox="0 0 16 16" aria-hidden="true"><rect x="3" y="3" width="10" height="10"/></svg>Stop</button>
        <div class="st-fader"><label for="v' . $k . '">Vol</label><input type="range" min="0" max="100" value="85" id="v' . $k . '"><output id="vo' . $k . '" for="v' . $k . '">85</output></div>
      </div>
    </div>
  </section>';
?>
<div class="st" id="studio" data-base="<?= h(App::url('')) ?>" data-csrf="<?= h($csrf) ?>" data-delay="<?= (int) $delay ?>" data-tz="<?= h($station['timezone']) ?>" data-meter="<?= h(App::asset('assets/outwave-meter.js')) ?>" data-encoder="<?= h(App::asset('assets/studio-encoder.js')) ?>">
  <section class="st-win st-top" aria-label="Show">
    <div class="st-brand">Studio<small><?= h($station['name']) ?> · live desk</small></div>
    <div class="st-show">
      <b id="stShow">Loading your show…</b>
      <div class="st-showbar" aria-hidden="true"><i id="stShowFill"></i></div>
      <div class="st-showmeta"><span id="stSlot"></span><span id="stListeners" title="Listening now, on the station page and embeds"></span><span id="stLeft"></span></div>
    </div>
    <div class="st-clock" id="stClock" aria-label="Station time">--:--:--</div>
    <div class="st-topacts">
      <button class="btn st-notesbtn" type="button" id="stNotesBtn" aria-expanded="false" aria-controls="stDrawer">Notes <span class="st-count" id="stNotesCount">0</span></button>
      <button class="btn st-golive" type="button" id="stGo" disabled><span class="dot"></span><span id="stGoLabel">Go live</span></button>
    </div>
  </section>

  <div class="st-banner" id="stBanner" role="status">
    <span id="stBannerText">The studio needs your microphone and sound. Wear headphones, so your speakers don’t feed back into the mic.</span>
    <span class="acts" id="stBannerActs"><button class="btn sm primary" type="button" id="stPower">Switch on the studio</button></span>
  </div>

  <section class="st-win st-out" id="stOutWin" aria-label="What goes out">
    <button class="st-out-tg" type="button" id="stOutTg" aria-expanded="true" aria-controls="stOutBox" title="Hide what goes out"><svg viewBox="0 0 12 12" aria-hidden="true"><path d="M3 4.5l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
    <span class="st-out-lbl">Going out</span>
    <div class="st-out-box" id="stOutBox">
      <canvas id="stOutCv" role="img" aria-label="What goes out: the sound scrolling past the white line (now); Deck A blue, Deck B pink, the mic yellow, the output a white line; the coming seconds dimmed on the right"></canvas>
      <div class="st-out-grip" id="stOutGrip" role="separator" aria-orientation="horizontal" aria-valuemin="30" aria-valuemax="240" aria-valuenow="50" aria-label="Height of the output waveform: drag, or use the arrow keys" tabindex="0"></div>
    </div>
  </section>

  <div class="st-row1">
    <?= $deck('A') ?>
    <section class="st-win st-mixer" aria-label="Mixer">
      <div class="st-titlebar"><span>Mixer</span></div>
      <div class="st-body">
        <div class="st-meters" aria-label="Levels going out">
          <div class="st-mcol"><div class="st-meter"><i id="mL"></i></div><span>L</span></div>
          <div class="st-mcol"><div class="st-meter"><i id="mR"></i></div><span>R</span></div>
          <div class="st-mcol"><div class="st-meter"><i id="mMic"></i></div><span><i class="st-dot mic" aria-hidden="true"></i>MIC</span></div>
        </div>
        <div>
          <div class="st-xf"><b>A</b><input type="range" id="xf" min="-100" max="100" value="-100" aria-label="Crossfader" disabled><b>B</b></div>
          <div class="st-xfbtns">
            <button class="btn sm" type="button" id="fadeBtn" disabled>Fade to B · 6 s</button>
            <button class="btn sm" type="button" id="skipBtn" disabled>Skip · 2 s fade</button>
          </div>
        </div>
        <div>
          <button class="btn st-talk" type="button" id="talk" aria-pressed="false" disabled>Mic on air</button>
          <div class="st-duck" id="duck">Music at full level</div>
        </div>
      </div>
    </section>
    <?= $deck('B') ?>
  </div>

  <div class="st-row2">
    <section class="st-win" aria-label="Microphone">
      <div class="st-titlebar"><span>Microphone</span></div>
      <div class="st-body">
        <div class="st-chainhead">
          <label class="st-ctrl"><span class="lbl">Input</span><select id="dev" disabled><option>Switch on the studio first</option></select></label>
          <label class="st-ctrl"><span class="lbl">Preset</span><select id="preset"><option>Preacher</option><option>Music show</option><option>Interview</option></select></label>
        </div>
        <div class="st-chain" id="chain"></div>
      </div>
    </section>
    <section class="st-win" aria-label="Running order">
      <div class="st-titlebar"><span>Running order</span><span class="st-state" id="roSaved"></span></div>
      <div class="st-body">
        <ol class="st-runorder" id="ro"></ol>
        <p class="st-empty" id="roEmpty">No items yet. Add songs, station IDs and talk below.</p>
        <div class="st-fill">
          <div class="st-fillbar" id="fillbar" aria-hidden="true"></div>
          <div class="st-fillmeta"><span id="fillTotal"></span><span id="fillRest"></span></div>
        </div>
        <div class="st-add" id="roAdd">
          <input type="search" id="libQ" placeholder="Find a song or station ID in the library" aria-label="Search the library" autocomplete="off">
          <ol class="st-results" id="libResults" aria-label="Library songs"></ol>
          <form class="st-talkadd" id="talkForm">
            <input type="text" id="talkTitle" maxlength="120" placeholder="Talk item, e.g. Welcome and prayer" aria-label="Talk item name">
            <input type="number" id="talkMin" min="0.5" max="120" step="0.5" value="4" aria-label="Minutes">
            <span class="unit">min</span>
            <button class="btn sm" type="submit">Add talk</button>
          </form>
        </div>
      </div>
    </section>
  </div>

  <section class="st-win st-status" aria-label="Connection">
    <span>Sending <b id="stPieceLen">6-second pieces</b> · last sent <b id="lastSent">–</b></span>
    <span>Waiting to send <b id="stQueue">0</b></span>
    <span>Listeners hear you <b><?= (int) $delay ?> s</b> later</span>
    <span class="st-outdev">Headphones <b id="stOut">this computer’s sound output</b><select class="st-outsel" id="stOutDev" aria-label="Headphones: where you hear the studio" hidden></select></span>
    <span class="st-rec" id="stRec"></span>
    <span class="st-bridge" id="stBridge" hidden>BUTT / Mixxx <b id="stBridgeState">waiting</b> <button class="btn sm ghost" type="button" id="stAppSet" title="The app's settings: the station's address, and the port and password for BUTT or Mixxx">App settings</button></span>
    <a class="st-getapp" id="stGetApp" href="<?= h((string) \Stationwagon\Config::get('desktop_download', 'https://controll.co.za/wp-json/stationwagon/v1/desktop/installer')) ?>" title="Stationwagon Studio for Windows: this studio, plus BUTT or Mixxx as your sound" hidden>Get the Windows app</a>
    <span class="st-conn" id="stConn">● Not sending</span>
  </section>
</div>

<aside class="st-drawer" id="stDrawer" aria-labelledby="stDrawerTitle" hidden>
  <div class="st-dhead">
    <h2 id="stDrawerTitle">Notes for listeners</h2>
    <button class="btn sm" type="button" id="stDrawerClose" aria-label="Close notes">Close</button>
  </div>
  <p class="st-dhint" id="stNotesHint">Go live to post notes. Listeners see them beside the player.</p>
  <div class="st-kinds" role="tablist" aria-label="Kind of note">
    <button class="btn sm" type="button" role="tab" data-kind="verse" aria-selected="true">Verse</button>
    <button class="btn sm" type="button" role="tab" data-kind="refs" aria-selected="false">References</button>
    <button class="btn sm" type="button" role="tab" data-kind="video" aria-selected="false">Video</button>
    <button class="btn sm" type="button" role="tab" data-kind="note" aria-selected="false">Note</button>
  </div>
  <form class="st-nform" id="noteForm" novalidate>
    <div class="st-nf" data-for="verse">
      <div class="st-two">
        <label class="field"><span class="lbl">Reference</span><input name="ref" maxlength="80" placeholder="Psalm 23:1-3"></label>
        <label class="field st-ver"><span class="lbl">Version</span><input name="version" maxlength="12" value="KJV" list="stVersions" autocapitalize="characters"></label>
      </div>
      <label class="field"><span class="lbl">Verse text (paste it)</span><textarea name="text" rows="5" maxlength="1500" placeholder="The LORD is my shepherd; I shall not want."></textarea></label>
    </div>
    <div class="st-nf" data-for="refs" hidden>
      <label class="field"><span class="lbl">Intro (optional)</span><input name="text" maxlength="200" placeholder="Read along with today’s message"></label>
      <div class="st-two">
        <label class="field"><span class="lbl">References, one per line</span><textarea name="refs" rows="4" placeholder="John 3:16&#10;Romans 8:28"></textarea></label>
        <label class="field st-ver"><span class="lbl">Version</span><input name="version" maxlength="12" value="KJV" list="stVersions" autocapitalize="characters"></label>
      </div>
    </div>
    <div class="st-nf" data-for="video" hidden>
      <label class="field"><span class="lbl">Title</span><input name="text" maxlength="200" placeholder="This week’s worship set"></label>
      <label class="field"><span class="lbl">Video address</span><input name="url" type="url" maxlength="500" placeholder="https://www.youtube.com/watch?v=…"></label>
    </div>
    <div class="st-nf" data-for="note" hidden>
      <label class="field"><span class="lbl">Note</span><textarea name="text" rows="5" maxlength="1000" placeholder="Thank you for listening. Prayer requests: …"></textarea></label>
    </div>
    <p class="st-nerr" id="noteErr" role="alert" hidden></p>
    <div class="st-nacts"><span class="st-dhint" id="noteHint">Listeners see it <?= (int) $delay ?> s after you post, when they hear this moment.</span><button class="btn" type="button" id="noteCancel" hidden>Cancel</button><button class="btn primary" type="submit" id="notePost" disabled>Post note</button></div>
  </form>
  <h3 class="st-posted">Posted in this show</h3>
  <ol class="st-nlist" id="noteList"></ol>
  <p class="st-dhint" id="noteNone">Nothing posted yet.</p>
  <datalist id="stVersions"><option value="KJV"><option value="NKJV"><option value="NIV"><option value="ESV"><option value="NLT"><option value="AFR53"><option value="AFR83"></datalist>
</aside>
<script src="<?= h(App::asset('assets/studio-mic.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/fades.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/outwave.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/podcast-core.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/studio-recorder.js')) ?>" defer></script>
<script src="<?= h(App::asset('assets/studio.js')) ?>" defer></script>
