// The "new version" dialog of Stationwagon Studio (Windows and Android), shown when the app opens and
// finds a newer version, and by "Check for updates" (round 58). It follows KDOHC's update dialog: a
// header "Version X is now available", what the release brings (the release's description on GitHub,
// served by the Stationwagon Updates plugin as `notes`), a box saying what happens next, and
// "Remind me later" / "Update now". "Remind me later" shows a second small notice that the update can
// be installed from App settings at any time.
//
// Its colours are the look picked in the studio (Green or Hi-fi): the studio page passes it to the
// app (set_look / setLook), and SwUpdate.applyLook() puts it on this page.
//
// The same file is in desktop/ui and mobile/www (app_test checks they are the same).
(() => {
  /** The look's tokens the app's own page uses, and only these (the studio page sends them). */
  const LOOK_KEYS = ["--ground", "--panel", "--raised", "--edge", "--ink", "--ink-2", "--muted", "--accent", "--accent-hover",
    "--on-accent", "--bad", "--warn", "--r-card", "--r-box", "--r-field", "--r-small", "--r-pill", "--card-edge", "--card-shadow",
    "--btn-shadow", "--f-body"];
  const SAFE = /^[\w\s#%(),.'"-]{1,200}$/;

  /** Puts the look saved from the studio ({ skin, vars }) on this page; anything unexpected is skipped. */
  function applyLook(look) {
    const root = document.documentElement;
    if (!look || typeof look !== "object" || !look.vars || typeof look.vars !== "object") return false;
    for (const k of LOOK_KEYS) {
      const v = look.vars[k];
      if (typeof v === "string" && SAFE.test(v) && !/url|expression/i.test(v)) root.style.setProperty(k, v);
    }
    if (typeof look.skin === "string" && /^[a-z]{1,20}$/.test(look.skin)) root.setAttribute("data-skin", look.skin);
    return true;
  }

  /**
   * The release description as lines: a line starting with `-`, `*` or a bullet is a bullet; a markdown
   * heading (`#`), a bold-only line (`**Fixes**`) or a short line ending in a colon (`Fixes:`) is a heading;
   * anything else is plain text. Bold, code ticks and links are flattened to plain text.
   */
  function parseNotes(notes) {
    const clean = (t) => t.replace(/\[([^\]]+)\]\([^)]*\)/g, "$1").replace(/\*\*|__|`/g, "").trim();
    const lines = [];
    for (const raw of String(notes || "").split(/\r?\n/)) {
      const line = raw.trim();
      if (!line || /^[-=*_]{3,}$/.test(line)) continue;
      const bullet = line.match(/^(?:[-*•]|\d+[.)])\s+(.*)$/);
      if (bullet) { const text = clean(bullet[1]); if (text) lines.push({ kind: "bullet", text }); continue; }
      const heading = line.match(/^#{1,6}\s+(.*)$/);
      const boldOnly = line.match(/^(?:\*\*|__)(.+?)(?:\*\*|__):?$/);
      const colon = line.length <= 60 && line.endsWith(":");
      if (heading || boldOnly || colon) {
        const text = clean((heading ? heading[1] : boldOnly ? boldOnly[1] : line).replace(/:$/, ""));
        if (text) lines.push({ kind: "heading", text });
        continue;
      }
      const text = clean(line);
      if (text) lines.push({ kind: "text", text });
    }
    return lines;
  }

  const ICON = {
    // Lucide's refresh-cw and triangle-alert (ISC licence)
    spin: '<svg class="swu-spin" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>',
    warn: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
  };

  const CSS = `
  .swu-overlay { position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center; padding: 16px; }
  .swu-overlay.android { background: rgba(0,0,0,.9); }
  .swu-card { background: var(--panel); color: var(--ink); border: var(--card-edge, 1px solid var(--edge)); box-shadow: var(--card-shadow, none), 0 20px 50px rgba(0,0,0,.5);
    border-radius: var(--r-card); width: 100%; max-width: 512px; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden; text-align: left; }
  .android .swu-card { max-width: 448px; max-height: 90vh; }
  .swu-head { padding: 16px; background: var(--accent); color: var(--on-accent); }
  .swu-head h3 { margin: 0; font-size: 18px; line-height: 1.35; font-weight: 700; }
  .android .swu-head h3 { font-size: 16px; }
  .swu-head p { margin: 0; opacity: .8; font-size: 15px; color: inherit; }
  .android .swu-head p, .android .swu-body { font-size: 14px; }
  .swu-body { padding: 20px; overflow-y: auto; font-size: 15px; line-height: 1.5; display: flex; flex-direction: column; gap: 12px; }
  .swu-body p { margin: 0; color: var(--ink); font-size: inherit; }
  .swu-body .swu-muted, .swu-muted { color: var(--ink-2); }
  .swu-h { font-weight: 700; } .swu-h.later { padding-top: 8px; }
  .swu-h.warn { display: flex; align-items: center; gap: 6px; color: var(--warn); } .swu-h.warn svg { flex-shrink: 0; }
  .swu-b { display: flex; gap: 8px; padding-left: 4px; } .swu-b span:last-child { min-width: 0; overflow-wrap: anywhere; }
  .swu-box { border: 1px solid var(--edge); border-radius: var(--r-small, 4px); padding: 12px; display: flex; flex-direction: column; gap: 4px; }
  .swu-body .swu-warnline { font-weight: 700; color: var(--warn); }
  .swu-body .swu-err { font-weight: 700; color: var(--bad); overflow-wrap: anywhere; }
  .swu-foot { display: flex; align-items: center; justify-content: flex-end; gap: 16px; padding: 16px 20px; border-top: 1px solid var(--edge); }
  .swu-link { background: none; border: 0; padding: 8px 4px; font: 700 15px/1.2 inherit; color: var(--ink-2); cursor: pointer; }
  .swu-link:hover { text-decoration: underline; } .swu-link:disabled { opacity: .5; cursor: default; text-decoration: none; }
  .swu-primary { display: inline-flex; align-items: center; gap: 8px; border: 0; padding: 8px 16px; border-radius: var(--r-small, 4px); font: 700 15px/1.2 inherit;
    background: var(--accent); color: var(--on-accent); box-shadow: var(--btn-shadow, none); cursor: pointer; }
  .swu-primary:hover { background: var(--accent-hover); } .swu-primary:disabled { opacity: .7; cursor: default; }
  .android .swu-link { padding: 12px 8px; font-size: 14px; }
  .android .swu-primary { padding: 12px 20px; border-radius: var(--r-box, 12px); font-size: 14px; font-weight: 900; }
  .swu-spin { animation: swu-spin 1s linear infinite; } @keyframes swu-spin { to { transform: rotate(360deg); } }`;

  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };

  /**
   * Shows the dialog. o: { latest, current, notes, android, install(onPercent) -> Promise (resolves when the
   * update is under way), later() (Remind me later, then OK), openSettings() (the notice's "Open App settings";
   * none: the notice is skipped, for "Check for updates" on the settings page itself) }.
   */
  function show(o) {
    close();
    if (!document.getElementById("swu-css")) { const s = el("style"); s.id = "swu-css"; s.textContent = CSS; document.head.append(s); }
    const overlay = el("div", "swu-overlay" + (o.android ? " android" : ""));
    overlay.id = "swuDialog";
    document.body.append(overlay);
    let status = "available", percent = null, failure = null, done = null;

    const reminder = () => {
      overlay.replaceChildren();
      const card = el("div", "swu-card");
      card.setAttribute("role", "dialog"); card.setAttribute("aria-modal", "true"); card.setAttribute("aria-label", "Update later");
      const body = el("div", "swu-body");
      body.append(el("p", "swu-h", "No problem, you can update later."));
      const p = el("p", "swu-muted");
      p.append("Open ", el("b", null, "App settings"),
        o.android ? " (the App settings button at the top of the studio)" : " (the App settings button at the top of the studio, next to BUTT / Mixxx)",
        ` at any time and use Check for updates there to install version ${o.latest}.`);
      body.append(p);
      const foot = el("div", "swu-foot");
      const open = el("button", "swu-link", "Open App settings"); open.type = "button"; open.id = "swuOpenSettings";
      const ok = el("button", "swu-primary", "OK"); ok.type = "button"; ok.id = "swuOk";
      open.onclick = () => { close(); o.openSettings(); };
      ok.onclick = () => { close(); o.later(); };
      foot.append(open, ok);
      card.append(body, foot);
      overlay.append(card);
      ok.focus();
    };

    const render = () => {
      overlay.replaceChildren();
      const busy = status === "downloading";
      const card = el("div", "swu-card");
      card.setAttribute("role", "dialog"); card.setAttribute("aria-modal", "true"); card.setAttribute("aria-label", `Version ${o.latest} is available`);
      const head = el("div", "swu-head");
      head.append(el("h3", null, `Version ${o.latest} is now available`));
      if (o.current) head.append(el("p", null, `You have version ${o.current}.`));
      const body = el("div", "swu-body");
      body.id = "swuNotes";
      const lines = parseNotes(o.notes);
      if (!lines.length) body.append(el("p", "swu-muted", "This update has no release notes."));
      lines.forEach((line, i) => {
        if (line.kind === "heading") {
          const warn = /^warnings?$/i.test(line.text);
          const p = el("p", "swu-h" + (i > 0 ? " later" : "") + (warn ? " warn" : ""));
          if (warn) p.innerHTML = ICON.warn;
          p.append(line.text);
          body.append(p);
        } else if (line.kind === "bullet") {
          const p = el("p", "swu-b");
          const dot = el("span", "swu-muted", "•"); dot.setAttribute("aria-hidden", "true");
          p.append(dot, el("span", null, line.text));
          body.append(p);
        } else body.append(el("p", "swu-muted", line.text));
      });
      const box = el("div", "swu-box");
      if (o.android) box.append(el("p", "swu-muted", "The update downloads inside the app, then your phone asks you to confirm the install."));
      else box.append(el("p", "swu-warnline", "The app will close while it updates, then open again by itself. Save any unsaved work first."));
      body.append(box);
      if (done && status === "available") { const d = el("p", "swu-h", done); d.id = "swuDone"; body.append(d); }
      if (status === "error") { const e = el("p", "swu-err", failure || "The update could not be started. Check your connection and try again."); e.id = "swuError"; body.append(e); }
      const foot = el("div", "swu-foot");
      const later = el("button", "swu-link", "Remind me later"); later.type = "button"; later.id = "swuLater"; later.disabled = busy;
      const now = el("button", "swu-primary"); now.type = "button"; now.id = "swuUpdate"; now.disabled = busy;
      if (busy) now.innerHTML = ICON.spin;
      now.append(busy ? (percent !== null && percent >= 0 ? `Downloading ${percent}%` : "Downloading…") : status === "error" ? "Try again" : "Update now");
      later.onclick = () => { if (o.openSettings) reminder(); else { close(); o.later(); } };
      now.onclick = async () => {
        if (status === "downloading") return;
        status = "downloading"; percent = null; failure = null; done = null; render();
        try {
          const said = await o.install((p) => { if (status !== "downloading") return; percent = p; const b = document.getElementById("swuUpdate"); if (b) b.lastChild.textContent = p >= 0 ? `Downloading ${p}%` : "Downloading…"; });
          // Windows: the app closes and restarts before this. Android: its own install prompt is showing;
          // the button is usable again in case the install is cancelled there
          done = typeof said === "string" ? said : null;
          status = "available"; render();
        } catch (e) {
          failure = (e && e.message) || (typeof e === "string" ? e : null);
          status = "error"; render();
        }
      };
      foot.append(later, now);
      card.append(head, body, foot);
      overlay.append(card);
      if (!busy) now.focus();
    };
    render();
  }

  function close() { const d = document.getElementById("swuDialog"); if (d) d.remove(); }

  window.SwUpdate = { show, close, parseNotes, applyLook, LOOK_KEYS };
})();
