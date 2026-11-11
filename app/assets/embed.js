// The small player other websites put in an iframe (views/embed.php): what's on from
// api/nowplaying.php, and Listen plays the station the way its own page does (player.js, round 60):
// the songs on two decks with the station's crossfades, live shows from the stream, all held at the
// listener delay, with the spectrum and the output strip (each song in its own colour) when the frame
// is tall enough. Before round 60 the embed played the HLS stream, which joins songs end to end with
// no crossfade. If something outside pauses the sound (a phone's power manager), or it stops moving,
// the player starts it again by itself, still counted as the same listener.
// The same page is the bar under the staff's screens (`bar`, round 61): long and flat, with a mute button,
// not counted as listening. It has no Listen button: it runs while it's open, always muted on opening, with a
// speaker button whose volume slider pops up out of it (the volume is remembered); it goes quiet by itself in the studio and Song prep.
(() => {
  const root = document.getElementById("embed");
  const BASE = root.dataset.base;
  const $ = (id) => document.getElementById(id);
  const P = window.SwPlayer; // player.js, loaded before this
  const POLL_MS = 15000;
  const CLOCK_MS = 300000; // a fresh clock sample this often (the player keeps its own while it plays)
  let np = null, offset = 0, timer = 0, polledAt = 0, clockSamples = [], clockAt = 0, on = false, note = "";
  const BAR = root.dataset.kind === "bar";

  const mmss = (s) => { s = Math.max(0, Math.round(s)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`; };
  const state = (text) => { $("emState").textContent = text; };
  const delay = () => (np ? np.delay : Number(root.dataset.delay) || 20);
  /** The server's clock: the player's while it plays, else this page's own from now playing. */
  const now = () => (on && P.serverNow()) || Date.now() / 1000 + offset;

  /**
   * The clock before Listen: now playing asked past Cloudflare's cache (a cached answer's time can be 2 s old),
   * halfway through the request, and of the last few the round trip with the shortest time wins.
   */
  async function sampleClock() {
    clockAt = Date.now();
    const sent = Date.now() / 1000;
    const r = await fetch(BASE + "api/nowplaying.php?clock=" + Math.random().toString(36).slice(2), { cache: "no-store" });
    if (!r.ok) throw new Error(String(r.status));
    const j = await r.json(), back = Date.now() / 1000;
    clockSamples = [...clockSamples, { rtt: back - sent, offset: j.server_now - (sent + back) / 2 }].slice(-5);
    offset = clockSamples.reduce((a, b) => (b.rtt < a.rtt ? b : a)).offset;
    return j;
  }

  async function poll() {
    clearTimeout(timer); polledAt = Date.now();
    try {
      if (!clockSamples.length || Date.now() - clockAt > CLOCK_MS) np = await sampleClock();
      else {
        const r = await fetch(BASE + "api/nowplaying.php", { cache: "no-store" }); // the shared, cached answer
        if (!r.ok) throw new Error(String(r.status));
        np = await r.json();
      }
      show();
    } catch (_) {
      if (!np) $("emTitle").textContent = "The station can’t be reached just now";
    }
    timer = setTimeout(poll, POLL_MS);
  }

  function show() {
    const air = np && np.on_air;
    const live = !!(air && air.type === "live");
    root.classList.toggle("live", live);
    $("emMode").textContent = live ? "Live" : "AutoDJ";
    $("emTitle").textContent = !air ? "Off air" : live ? air.title + (air.dj ? ` · ${air.dj}` : "")
      : air.title + (air.artist ? ` · ${air.artist}` : "");
    const next = np && np.next && np.next[0];
    $("emNext").textContent = next && !live ? `Next: ${next.title}` : "";
    songLook(air && !live ? air : null);
    tick();
  }

  /** The song's album art in place of the CD, and its copyright line under the title (round 45); a click opens the details. */
  let rightsOf = null, artShown = null;
  function songLook(song) {
    const art = song && song.art ? BASE + song.art : null;
    if (art !== artShown) {
      artShown = art;
      const img = $("emArtImg");
      img.hidden = !art;
      if (art) img.src = art; else img.removeAttribute("src");
      $("emArt").classList.toggle("has-art", !!art);
    }
    rightsOf = song && song.rights ? song : null;
    const b = $("emRights");
    b.hidden = !rightsOf;
    b.textContent = rightsOf ? rightsOf.rights.line : "";
    if (!rightsOf) $("emRDlg").hidden = true;
  }
  $("emRights").addEventListener("click", () => {
    if (!rightsOf || !window.SwRights) return;
    $("emRSong").textContent = rightsOf.title + (rightsOf.artist ? ` · ${rightsOf.artist}` : "");
    $("emRBody").replaceChildren(SwRights.details(rightsOf.rights));
    $("emRDlg").hidden = false;
    $("emRClose").focus();
  });
  $("emRClose").addEventListener("click", () => { $("emRDlg").hidden = true; $("emRights").focus(); });

  /** Heard: playing, and a song deck (or the stream) is up, not still silent while it joins. */
  const heard = () => { const b = on && P.heard(); return !!(b && !b.el.paused && b.gain > 0.5); };

  // the bar and time move between polls on the server's clock, at the listener delay
  function tick() {
    const air = np && np.on_air;
    if (!air) return;
    const h = now() - delay();
    if (air.type === "song" && h >= air.ends && Date.now() - polledAt > 3000) { poll(); return; } // the next song has started
    const elapsed = h - air.started;
    $("emFill").style.width = air.type === "song" && air.duration > 0 ? `${Math.min(100, (elapsed / air.duration) * 100)}%` : "100%";
    if (on && heard()) state(air.type === "song" ? `${mmss(elapsed)} / ${mmss(air.duration)}` : `Live for ${mmss(elapsed)}`);
  }
  setInterval(tick, 500);

  // what the player says: connecting, playing, blocked by the browser, stopped
  root.addEventListener("sw:player", (e) => {
    const d = e.detail;
    on = d.on; note = d.note || "";
    if (!BAR) { $("emPlay").setAttribute("aria-pressed", on ? "true" : "false"); $("emPlay").setAttribute("aria-label", on ? "Stop" : "Listen"); }
    root.classList.toggle("playing", on);
    if (note) state(note);
    else if (!on && BAR) state(d.status === "Blocked" ? "Press the speaker to hear it" : "");
    else if (!on) state(d.status === "Blocked" ? "The browser blocked the sound: press play again" : "Press play to listen");
    else if (!heard()) state(d.status === "Reconnecting" ? "Reconnecting…" : "Connecting…");
    tick();
  });

  if (!BAR) $("emPlay").addEventListener("click", () => (on ? P.stop() : P.start()));

  // ---- The bar: mute, and what it remembers between screens and visits -------------------------------------
  let muted = false;
  function setMuted(m, auto = false) {
    muted = !!m; P.mute(muted);
    root.classList.toggle("is-muted", muted);
    // the bar's slider shows what is heard: at the bottom while muted (the remembered volume is kept, and comes back
    // when the speaker is pressed again), so that raising it from there starts quietly (round 66)
    const slider = BAR ? $("emVol") : null;
    if (slider) slider.value = muted ? 0 : Math.round(P.volume() * 100);
    const b = $("emMute");
    b.setAttribute("aria-pressed", muted ? "true" : "false"); b.setAttribute("aria-label", muted ? "Unmute" : "Mute"); b.title = muted ? (auto ? "Muted while you work here: press to listen" : "Unmute") : "Mute";
  }
  if (BAR) {
    setMuted(true); // always quiet on opening (the user, round 61)
    // the speaker: heard or quiet; if the browser held the sound back until a tap, this tap starts it
    $("emMute").addEventListener("click", () => {
      if (muted && P.volume() < 0.05) P.volume(0.5); // it was left at the bottom: pressing the speaker must make sound
      setMuted(!muted); if (!on) P.start();
    });
    const vol = $("emVol"), pop = $("emVolPop"), sound = $("emSound");
    // the bar is as tall as the room its host gives it (84 px in the Windows app and a wide browser, 64 on a
    // phone); while the slider is up the host gives it 150 px more, and the bar keeps its own height at the foot
    // (in the Windows app the view is always as tall as the slider needs and only its foot shows: the bar is 84 px there)
    const fit = () => {
      if (window.__TAURI__ && window.__TAURI__.core) { root.style.setProperty("--bar-h", "84px"); root.classList.add("roomy"); return; }
      if (!pop.hidden) return;
      root.style.setProperty("--bar-h", innerHeight + "px"); root.classList.toggle("roomy", innerHeight >= 76);
    };
    fit();
    window.addEventListener("resize", fit);
    vol.value = muted ? 0 : Math.round(P.volume() * 100);
    vol.addEventListener("input", () => {
      const v = vol.value / 100;
      if (muted) { if (v > 0) { P.volume(v); setMuted(false); } } // from the bottom up: it starts quietly, and the speaker comes on
      else P.volume(v);
      if (!on) P.start();
    });
    // the slider pops up out of the speaker: the page around the bar (the frame page, or an app) makes room for it
    let shut = 0;
    const tall = (t) => {
      try { if (window.parent !== window) window.parent.postMessage({ sw: "bar-tall", tall: t }, location.origin); } catch (_) { /* another site */ }
      if (window.SwBarHost && window.SwBarHost.tall) try { window.SwBarHost.tall(t); } catch (_) { /* the Android app's own */ }
      if (window.__TAURI__ && window.__TAURI__.core) window.__TAURI__.core.invoke("bar_tall", { tall: t }).catch(() => {}); // the Windows app's
    };
    const openPop = () => { clearTimeout(shut); if (pop.hidden) { tall(true); pop.hidden = false; } };
    // it closes when the pointer has left, unless the keyboard is using it
    let mouseIn = false; // a phone's tap leaves :hover stuck, so only a mouse counts
    const inUse = () => mouseIn || !!sound.querySelector(":focus-visible");
    const closePop = (ms = 350) => { clearTimeout(shut); shut = setTimeout(() => { if (pop.hidden) return; if (inUse()) { closePop(1500); return; } if (sound.contains(document.activeElement)) document.activeElement.blur(); pop.hidden = true; setTimeout(() => { if (pop.hidden) tall(false); }, 90); }, ms); };
    sound.addEventListener("pointerenter", (e) => { if (e.pointerType === "mouse") { mouseIn = true; openPop(); } });
    sound.addEventListener("pointerleave", (e) => { if (e.pointerType === "mouse") { mouseIn = false; closePop(); } });
    sound.addEventListener("pointerdown", (e) => { if (e.pointerType !== "mouse") { openPop(); closePop(4000); } }); // a tap: up for a few seconds
    vol.addEventListener("pointerdown", () => clearTimeout(shut));
    vol.addEventListener("change", () => closePop(2500));
    sound.addEventListener("focusin", openPop);
    sound.addEventListener("focusout", () => closePop(200));
    vol.addEventListener("keydown", (e) => { if (e.key === "Escape") { vol.blur(); $("emMute").focus(); pop.hidden = true; setTimeout(() => { if (pop.hidden) tall(false); }, 90); } });
    P.start(); // runs while it's open: the strip and the time move, muted
    /** The screen above changed (the frame page or the app says so): the studio and Song prep mute the bar. */
    const screen = (route) => { if (/^(studio|manage\/songprep)(\/|$)/.test(String(route || "")) && !muted) setMuted(true, true); };
    window.SwBar = { screen, mute: (m) => setMuted(m), get muted() { return muted; }, volume: (v) => { if (v !== undefined) { const set = P.volume(v); if (!muted) vol.value = Math.round(set * 100); } return P.volume(); }, get open() { return !pop.hidden; },
      // the Android app hides its bar's view without the page knowing: told, the bar stops drawing until it shows again
      hide: (h) => { document.documentElement.style.display = h ? "none" : ""; } };
    window.addEventListener("message", (e) => { if (e.origin === location.origin && e.data && e.data.sw === "screen") screen(e.data.route); });
  }
  // for the tests: whether it plays, is heard, and how far behind the server's clock
  window.swEmbed = {
    get playing() { const b = on && P.heard(); return !!(b && !b.el.paused); },
    get heard() { return heard(); },
    get lag() { const b = on && P.heard(); return b && b.lag !== null ? b.lag : null; },
    get np() { return np; },
    get ping() { return P.state().ping; },
    get events() { return P.state().events; },
    pauseFromOutside() { const b = P.heard(); if (b) b.el.pause(); }, // as a phone's power manager does
    get media() { const b = P.heard(); return b ? b.el : null; },
    get offset() { return offset; },
    slip(s) { return P.slip(s); }, // the song heard slips s seconds
    poll: () => poll(),
    get note() { return note; },
    get muted() { return BAR ? muted : false; },
    // the visuals: whether they run, the spectrum's state, how much of the strip is measured and planned
    get vis() {
      const s = P.state(), t = P.wave.now();
      return { on: !!s.engine, kind: s.wave ? s.wave.meter : null, spectrum: s.spectrum,
        measured: t === null ? null : P.wave.measured(t - 4, t), planned: t === null ? null : P.wave.planned(t + 0.5, t + 4) };
    },
  };
  poll();
})();
