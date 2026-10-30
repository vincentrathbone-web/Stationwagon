// The player's Diagnostics panel (views/player.php, between the player and the playlist): what the
// device, this page, the sound engine, the song download and the network are doing, each with a
// green, amber or red dot, a line naming the likely cause of dropouts, and the dropouts themselves
// with what each one was. It measures only while the panel is open (the device remembers that).
// "Send report" saves the same text on the station's server (api/diag.php); "Copy" copies it.
(() => {
  "use strict";
  const box = document.getElementById("lpDiag"), root = document.getElementById("player");
  if (!box || !root) return;
  const $ = (id) => document.getElementById(id);
  const BASE = new URL(root.dataset.base, location.href).href;
  const WINDOW = 10000; // the "last 10 s" the rates are worked out over
  const KEY = "sw-diag-open";
  let timer = 0, raf = 0, observer = null, frames = [], tasks = [], costs = [], speed = null, speedAt = -Infinity, probing = false, last = null;

  try { if (localStorage.getItem(KEY) === "1") box.open = true; } catch (_) { /* private window */ }
  box.addEventListener("toggle", () => {
    try { localStorage.setItem(KEY, box.open ? "1" : "0"); } catch (_) { /* private window */ }
    box.open ? start() : stop();
  });

  function start() {
    if (timer) return;
    frames = []; tasks = []; costs = [];
    const count = (t) => { frames.push(t); raf = requestAnimationFrame(count); };
    raf = requestAnimationFrame(count);
    // freezes: the browser's long tasks where it reports them (Chrome, Edge), else gaps between frames
    if (window.PerformanceObserver && (PerformanceObserver.supportedEntryTypes || []).includes("longtask")) {
      observer = new PerformanceObserver((list) => { for (const e of list.getEntries()) tasks.push({ at: e.startTime, ms: e.duration }); });
      observer.observe({ type: "longtask", buffered: false });
    }
    timer = setInterval(update, 1000);
    update();
  }
  function stop() {
    clearInterval(timer); timer = 0;
    cancelAnimationFrame(raf); raf = 0;
    if (observer) { observer.disconnect(); observer = null; }
  }

  /** Download speed: a timed read of 256 KB of the song playing (or the stream), every 30 s while open. */
  async function probe(s) {
    if (probing || performance.now() - speedAt < 30000 || !window.SwPlayer) return;
    const tl = SwPlayer.timeline && SwPlayer.timeline();
    const cur = tl && s.current && tl.entries.find((e) => e.start === s.current.start);
    const url = cur && cur.url ? new URL(cur.url, BASE).href : null;
    if (!url) return;
    probing = true; speedAt = performance.now();
    try {
      const t0 = performance.now();
      const res = await fetch(url + (url.includes("?") ? "&" : "?") + "probe=" + Date.now(), { headers: { Range: "bytes=0-262143" }, cache: "no-store" });
      const bytes = (await res.arrayBuffer()).byteLength;
      const secs = (performance.now() - t0) / 1000;
      if (res.ok && bytes > 16384) speed = { mbps: (bytes * 8) / secs / 1e6, bytes, ms: Math.round(secs * 1000) };
    } catch (_) { speed = { mbps: 0, failed: true }; }
    probing = false;
  }

  /** What the current song needs per second, from its pieces (bytes and seconds), in Mbps. */
  function needs(s) {
    const tl = SwPlayer.timeline && SwPlayer.timeline();
    const cur = tl && s.current && tl.entries.find((e) => e.start === s.current.start);
    if (!cur || !cur.pieces || !cur.pieces.length) return 0.2;
    let b = 0, t = 0;
    for (const [, len, sec] of cur.pieces) { b += len; t += sec; }
    return t > 0 ? (b * 8) / t / 1e6 : 0.2;
  }

  function measure() {
    const now = performance.now(), from = now - WINDOW;
    frames = frames.filter((t) => t > from); tasks = tasks.filter((t) => t.at > from);
    const s = window.SwPlayer ? SwPlayer.state() : null;
    const st = s && s.diag && s.diag.sound ? s.diag.sound.stats : null;
    if (s && s.diag) { costs.push({ at: now, c: s.diag.cost, u: st ? st.underrunEvents ?? st.fallbackFramesEvents ?? null : null }); costs = costs.filter((x) => x.at > from - 1000); }
    const span = frames.length > 1 ? (frames[frames.length - 1] - frames[0]) / 1000 : 0;
    const fps = span > 1 && !document.hidden ? (frames.length - 1) / span : null;
    let freezes = tasks.length, worst = tasks.reduce((m, t) => Math.max(m, t.ms), 0), busy = tasks.reduce((a, t) => a + t.ms, 0);
    if (!observer) { // no long-task reports: count frames that came 50 ms or more late
      freezes = 0; worst = 0; busy = 0;
      for (let i = 1; i < frames.length; i++) { const g = frames[i] - frames[i - 1]; if (g >= 50) { freezes++; worst = Math.max(worst, g); busy += g - 16.7; } }
    }
    let cost = null;
    if (costs.length > 1) {
      const a = costs[0], b = costs[costs.length - 1], secs = (b.at - a.at) / 1000;
      if (secs > 0.5) cost = { strip: (b.c.strip - a.c.strip) / secs, spectrum: (b.c.spectrum - a.c.spectrum) / secs, mix: (b.c.mix - a.c.mix) / secs };
    }
    // the browser's own count of moments the sound engine had nothing ready to play (Chrome), in the last 10 s
    const withU = costs.filter((x) => x.u !== null);
    const underruns = withU.length > 1 ? withU[withU.length - 1].u - withU[0].u : null;
    const conn = navigator.connection || null;
    return { s, fps, freezes, worst, busy: busy / WINDOW, cost, conn, needs: s ? needs(s) : 0.2, speed, underruns, totalUnderruns: withU.length ? withU[withU.length - 1].u : null };
  }

  const dot = (lvl) => `<i class="d ${lvl}" aria-label="${lvl === "good" ? "fine" : lvl === "warn" ? "worth a look" : "a problem"}"></i>`;
  const n1 = (v) => (v === null || v === undefined ? "–" : (+v).toFixed(1));
  const ms = (v) => (v === null || v === undefined ? "–" : Math.round(v) + " ms");
  const clockTime = (t) => new Date(t).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false });

  /** The five rows: [name, level, main line, second line]. */
  function rows(m) {
    const s = m.s, d = s && s.diag, out = [];
    const fpsTxt = m.fps === null ? (document.hidden ? "page hidden" : "measuring…") : Math.round(m.fps) + " fps";
    const scr = m.fps === null ? "good" : m.fps >= 50 && m.busy < 0.1 ? "good" : m.fps >= 30 && m.busy < 0.25 ? "warn" : "bad";
    out.push(["Screen", scr, `${fpsTxt} · ${m.freezes} freeze${m.freezes === 1 ? "" : "s"} over 50 ms${m.freezes ? ` (worst ${Math.round(m.worst)} ms)` : ""}`, `busy ${Math.round(m.busy * 100)} % of the last 10 s`]);
    const c = m.cost, total = c ? c.strip + c.spectrum + c.mix : null;
    out.push(["Player", total === null ? "good" : total < 50 ? "good" : total < 150 ? "warn" : "bad",
      c ? `strip ${ms(c.strip)} · spectrum ${ms(c.spectrum)} · mix-ahead ${ms(c.mix)}` : "measuring…", "time this page spends per second"]);
    if (s && s.engine) {
      const snd = d.sound || {}, st = snd.stats;
      const gapsTxt = `sound gaps ${d.gaps}` + (m.totalUnderruns !== null ? ` · underruns ${m.underruns ?? 0} in 10 s (${m.totalUnderruns} in all)` : "");
      out.push(["Sound", m.underruns || d.stalls ? "bad" : d.gaps || s.engine.state !== "running" ? "warn" : "good",
        `${s.engine.state} · ${snd.rate ? Math.round(snd.rate / 1000) + " kHz" : "–"} · output delay ${snd.out ? Math.round(snd.out * 1000) + " ms" : "–"}`, `engine stalls ${d.stalls} · ${gapsTxt}`]);
    } else {
      out.push(["Sound", "good", s && s.on ? "stream only (no sound engine)" : "not playing", "press Listen to measure"]);
    }
    if (d) {
      const buf = d.buffered.filter((v) => v !== null), b = buf.length ? Math.max(...buf) : null;
      const lvl = d.waits || (b !== null && b < 2) ? "bad" : d.corrections || (d.drift !== null && Math.abs(d.drift) > 0.15) ? "warn" : "good";
      out.push(["Song", s.on ? lvl : "good", `${b === null ? "–" : n1(b) + " s"} buffered ahead · drift ${d.drift === null ? "–" : (d.drift > 0 ? "+" : "") + d.drift.toFixed(2) + " s"}`,
        `jumps ${d.corrections} · waits for data ${d.waits}`]);
    }
    const sp = m.speed, need = m.needs, cn = m.conn;
    const net = !sp ? "good" : sp.failed || sp.mbps < need * 1.2 ? "bad" : sp.mbps < need * 3 || (d && d.fails) ? "warn" : "good";
    out.push(["Network", net, `song ${sp ? (sp.failed ? "download failed" : n1(sp.mbps) + " Mbps") : "measuring…"} (needs ${n1(need)}) · round trip ${d && d.rtt !== null ? d.rtt + " ms" : "–"}`,
      `${cn ? [cn.effectiveType, cn.downlink ? cn.downlink + " Mbps said" : "", cn.saveData ? "data saver on" : ""].filter(Boolean).join(" · ") + " · " : ""}failed requests ${d ? d.fails : 0}`]);
    return out;
  }

  /** The likely cause, from the strongest signal. */
  function verdict(m) {
    const s = m.s, d = s && s.diag;
    if (!s || !s.on) return "Press Listen to start measuring.";
    const recent = (d.dropLog || []).filter((x) => Date.now() - x.at < 5 * 60000);
    const by = (k) => recent.filter((x) => x.cause === k).length;
    const sp = m.speed;
    const dry = recent.filter((x) => (x.cause === "waiting for data" && /(^|, )[0-2]\.\d s buffered|the stream/.test(x.detail)) || x.cause === "stream buffer ran empty").length;
    if (dry) return sp && sp.mbps < m.needs * 1.5 ? "Likely cause: the internet connection is too slow for the music." : "Likely cause: the download ran dry (the connection stalled for a moment).";
    if (by("waiting for data")) return "Likely cause: the song paused although plenty was downloaded (the device’s media player hiccupped).";
    if (by("timing correction")) return "Likely cause: the song slipped over a second out of step and jumped back (often after the device held it up).";
    if (by("sound engine stalled")) return "Likely cause: the device paused its sound engine (often power saving).";
    if (by("no answer from the station")) return "Likely cause: the connection to the station dropped.";
    const cost = m.cost ? m.cost.strip + m.cost.spectrum + m.cost.mix : 0;
    if ((m.fps !== null && m.fps < 30) || m.busy > 0.25) return cost > 100 ? `Likely cause: device busy, mostly this page’s drawing and mixing (${Math.round(cost)} ms a second).` : `Likely cause: device busy (screen ${m.fps === null ? "–" : Math.round(m.fps)} fps, ${m.freezes} freezes).`;
    if (m.underruns) return "Likely cause: the sound engine ran out of sound to play (underruns): the device can’t keep up.";
    if (by("sound gap")) return "Likely cause: the sound went silent with no other sign: send a report.";
    if (sp && sp.mbps < m.needs * 1.5) return "Watch out: the connection is only just fast enough.";
    return recent.length ? "Dropouts in the last 5 minutes: see the list below." : "All good: nothing is holding the sound back.";
  }

  function update() {
    const m = measure();
    last = m;
    if (m.s && m.s.on) probe(m.s);
    const v = verdict(m);
    $("lpDiagVerdict").textContent = v;
    $("lpDiagVerdict").className = /^All good|^Press/.test(v) ? "" : "warn";
    $("lpDiagRows").innerHTML = rows(m).map(([name, lvl, a, b]) => `<div class="r">${dot(lvl)}<dt>${name}</dt><dd>${esc(a)}<small>${esc(b)}</small></dd></div>`).join("");
    const drops = (m.s && m.s.diag ? m.s.diag.dropLog : []).slice().reverse();
    $("lpDiagDropN").textContent = String(m.s ? m.s.drops : 0);
    $("lpDiagDrops").innerHTML = drops.length ? drops.map((x) => `<li><span>${clockTime(x.at)}</span>${esc(x.cause)}${x.detail ? ` <small>(${esc(x.detail)})</small>` : ""}</li>`).join("") : "<li class=\"none\">None yet</li>";
    $("lpDiagSum").textContent = v.replace(/^Likely cause: /, "").replace(/^All good.*/, "all good").replace(/^Press Listen.*/, "");
  }
  const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c]);

  /** The report: the same rows as text, the dropouts, the player's events and the device. */
  function report() {
    const m = last || measure(), s = m.s, n = navigator, sc = window.screen || {};
    const lines = [
      `Stationwagon diagnostics: ${root.dataset.station || ""}`, `Page: ${location.href}`, `Taken: ${new Date().toISOString()}`, "",
      `Device: ${n.userAgent}`,
      `Cores ${n.hardwareConcurrency || "?"} · memory ${n.deviceMemory ? n.deviceMemory + " GB" : "?"} · screen ${sc.width}×${sc.height} at ${window.devicePixelRatio || 1}x · window ${innerWidth}×${innerHeight}`,
      `Connection: ${m.conn ? JSON.stringify({ type: m.conn.effectiveType, downlink: m.conn.downlink, rtt: m.conn.rtt, saveData: m.conn.saveData }) : "not reported"}`,
      `Reduced motion: ${matchMedia("(prefers-reduced-motion: reduce)").matches ? "on" : "off"} · page ${document.hidden ? "hidden" : "visible"}`, "",
      verdict(m), "",
      ...rows(m).map(([name, lvl, a, b]) => `[${lvl}] ${name}: ${a} | ${b}`), "",
      `Dropouts: ${s ? s.drops : 0}`,
      ...((s && s.diag ? s.diag.dropLog : []).map((x) => `  ${new Date(x.at).toISOString()} ${x.cause}${x.detail ? " (" + x.detail + ")" : ""}`)), "",
      "Player events (heard time, latest last):", ...((s ? s.events : []).map((e) => "  " + e)), "",
      "State: " + JSON.stringify(s ? { status: s.status, mode: s.mode, current: s.current, decks: s.decks, engine: s.engine, stream: s.stream, wave: s.wave, diag: { ...s.diag, dropLog: undefined } } : null),
      `Speed probe: ${JSON.stringify(m.speed)}`,
    ];
    return lines.join("\n");
  }
  const msg = (t) => { $("lpDiagMsg").textContent = t; };

  $("lpDiagSend").addEventListener("click", async () => {
    msg("Sending…");
    try {
      const res = await fetch(BASE + "api/diag.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ report: report() }) });
      const j = await res.json().catch(() => ({}));
      msg(res.ok ? `Sent. Report ${j.id}: mention this number when you ask for help.` : j.error || "Couldn’t send it. Try Copy instead.");
    } catch (_) { msg("Couldn’t send it. Try Copy instead."); }
  });
  $("lpDiagCopy").addEventListener("click", async () => {
    const text = report();
    try { await navigator.clipboard.writeText(text); msg("Copied. Paste it wherever you ask for help."); }
    catch (_) {
      const t = document.createElement("textarea"); t.value = text; document.body.appendChild(t); t.select();
      try { document.execCommand("copy"); msg("Copied. Paste it wherever you ask for help."); } catch (__) { msg("Couldn’t copy here."); }
      t.remove();
    }
  });
  window.SwDiag = { report, measure: () => measure(), open: () => { box.open = true; start(); } };
  if (box.open) start(); // left open last time: measure from the start (after everything above is defined)
})();
