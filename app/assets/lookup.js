// "Look up details" in the song details window (round 65): suggestions for one song from MusicBrainz, the free open
// music database, asked for by a manager (the server asks; this page never contacts anyone else). The window's
// owner (library.js) says what the song is and how to read and write its fields; this file draws the steps:
//   1. Look up: the server searches MusicBrainz by ISRC, or by title and artist, and the best match is chosen;
//   2. its details come back (album, year, track, label, ISRC, and from the work: writers, lyricists, publishers, ISWC);
//   3. a table shows each field now and as found, with a tick; the fields that are empty now are ticked;
//   4. "Fill the ticked fields" writes them into the window (nothing is saved until the window's own Save).
// A cover can be shown and, when the song has no album art, used. Everything MusicBrainz sends is shown as text only.
(() => {
  const box = document.getElementById("lkBox");
  if (!box || !window.fetch || !window.FormData) return;
  const $ = (id) => document.getElementById(id);
  let ctx = null, details = null, picks = [], coverBlob = null;

  const ROWS = [["album", "Album"], ["year", "Year"], ["track", "Track number"], ["label", "Record label"], ["isrc", "ISRC"],
    ["writers", "Songwriter(s)"], ["lyricists", "Lyricist(s)"], ["publisher", "Publisher(s)"], ["iswc", "ISWC"]];
  const found = (d) => ({ album: d.album, year: d.year, track: d.track, label: d.label, isrc: d.isrc, writers: d.writers.join("; "),
    lyricists: d.lyricists.join("; "), publisher: d.publishers.join("; "), iswc: d.iswc });

  const say = (text, bad) => { const m = $("lkMsg"); m.textContent = text || ""; m.classList.toggle("bad", !!bad); };
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };

  async function post(fields, blob) {
    const data = new FormData();
    data.append("csrf", ctx.csrf);
    for (const [k, v] of Object.entries(fields)) if (v !== null && v !== undefined && v !== "") data.append(k, v);
    let r;
    try { r = await fetch(ctx.action, { method: "POST", body: data, headers: { Accept: blob ? "image/*, application/json" : "application/json" }, credentials: "same-origin" }); }
    catch (_) { throw new Error("The connection dropped. Try again."); }
    if (blob && /^image\//.test(r.headers.get("Content-Type") || "")) return r.blob();
    let j = null;
    try { j = await r.json(); } catch (_) { /* not JSON */ }
    if (!r.ok || !j || j.ok === false) throw new Error((j && j.error) || "That didn’t work (" + r.status + ").");
    return j;
  }

  function reset() {
    details = null; picks = []; coverBlob = null;
    $("lkCands").hidden = true; $("lkCands").textContent = "";
    $("lkResult").hidden = true; $("lkRows").textContent = "";
    $("lkCover").hidden = true; $("lkWarn").hidden = true;
    $("lkRun").disabled = false; say("");
  }

  // ---- 1. the search ---------------------------------------------------------------------------------
  async function run() {
    if (!ctx) return;
    const q = ctx.query();
    reset();
    if (!String(q.title || "").trim() && !String(q.isrc || "").trim()) { say("Give the song a title (or an ISRC) first.", true); return; }
    $("lkRun").disabled = true;
    say("Searching MusicBrainz… this takes a few seconds.");
    try {
      const j = await post({ action: "lookup", title: q.title, artist: q.artist, isrc: q.isrc, seconds: q.seconds });
      if (!j.candidates.length) { say("MusicBrainz doesn’t seem to know this song. Music that isn’t from a record label (production music, home recordings) often isn’t in it.", true); return; }
      showCandidates(j.candidates);
      await choose(0);
    } catch (ex) { say(ex.message, true); }
    finally { $("lkRun").disabled = false; }
  }

  function showCandidates(list) {
    picks = list;
    const ol = $("lkCands");
    ol.textContent = "";
    list.forEach((c, i) => {
      const li = el("li");
      const lab = el("label", "lkpick");
      const r = el("input"); r.type = "radio"; r.name = "lkPick"; r.value = String(i); r.checked = i === 0;
      r.addEventListener("change", () => choose(i));
      const main = el("span", "lkmain", c.title + (c.artist ? " — " + c.artist : ""));
      const bits = [c.year, c.seconds ? Math.floor(c.seconds / 60) + ":" + String(Math.round(c.seconds % 60)).padStart(2, "0") : "", c.diff !== null && c.diff !== undefined ? (c.diff <= 3 ? "length agrees" : "length differs by " + Math.round(c.diff) + " s") : ""].filter(Boolean).join(" · ");
      const tag = el("span", "pill " + (c.confidence === "likely" ? "auto" : ""), c.confidence === "likely" ? "Likely" : "Check");
      lab.append(r, main, " ", tag, el("span", "sub", bits));
      li.append(lab);
      ol.append(li);
    });
    ol.hidden = list.length < 2;
  }

  // ---- 2. a recording's details ------------------------------------------------------------------------
  async function choose(i, release) {
    const c = picks[i];
    say("Getting the details… a few more seconds.");
    $("lkResult").hidden = true;
    try {
      const j = await post({ action: "lookup_detail", recording: c.mbid, release: release || "", title: c.title, artist: c.artist });
      details = j.details;
      show();
      say("");
    } catch (ex) { say(ex.message, true); }
  }

  // ---- 3. the table of what was found ---------------------------------------------------------------------
  function show() {
    const d = details, f = found(d), body = $("lkRows");
    body.textContent = "";
    for (const [name, label] of ROWS) {
      const now = String(ctx.get(name) || "").trim(), val = String(f[name] || "").trim();
      const tr = el("tr");
      const td0 = el("td"), cb = el("input"); cb.type = "checkbox"; cb.dataset.field = name; cb.setAttribute("aria-label", "Use " + label);
      const same = val !== "" && now.toLowerCase() === val.toLowerCase();
      cb.disabled = val === "" || same;
      cb.checked = val !== "" && now === "";
      td0.append(cb);
      tr.append(td0, el("td", "lkf", label), el("td", "lknow", now || "—"), el("td", "lkval", val === "" ? "not in MusicBrainz" : val + (same ? "  (same)" : "")));
      tr.classList.toggle("none", val === "");
      body.append(tr);
    }
    const tr = el("tr"), td0 = el("td"), cb = el("input");
    cb.type = "checkbox"; cb.dataset.field = "_source"; cb.checked = true; cb.setAttribute("aria-label", "Add a link to MusicBrainz in the notes");
    td0.append(cb);
    tr.append(td0, el("td", "lkf", "Source"), el("td", "lknow", ""), el("td", "lkval", "Add a link to this recording on MusicBrainz in the notes"));
    body.append(tr);

    // which release (the album, year, label and track come from it)
    const sel = $("lkRel");
    sel.textContent = "";
    for (const r of d.releases) {
      const o = el("option", "", [r.title, r.date, r.country].filter(Boolean).join(" · ") + (r.status && r.status !== "Official" ? " (" + r.status + ")" : ""));
      o.value = r.mbid; o.selected = !!d.release && r.mbid === d.release.mbid;
      sel.append(o);
    }
    $("lkRelWrap").hidden = d.releases.length < 2;

    const warn = $("lkWarn");
    warn.hidden = !(d.work && d.work.linked === false);
    if (!warn.hidden) warn.textContent = "The writers, publishers and ISWC come from the work “" + d.work.title + "”, which MusicBrainz doesn’t link to this recording. They are probably right for a song of this name, but check them.";
    else if (!d.work) { warn.hidden = false; warn.textContent = "MusicBrainz has no writers or publishers for this song."; }
    showCover();
    $("lkResult").hidden = false;
  }

  // ---- the cover ------------------------------------------------------------------------------------------
  function showCover() {
    const d = details, wrap = $("lkCover"), img = $("lkCoverImg"), use = $("lkCoverUse"), msg = $("lkCoverMsg"), get = $("lkCoverShow");
    coverBlob = null;
    img.hidden = true; img.removeAttribute("src"); use.hidden = true; msg.textContent = "";
    wrap.hidden = !(d.cover && d.release);
    get.hidden = wrap.hidden;
  }
  async function showCoverNow() {
    const msgEl = $("lkCoverMsg");
    msgEl.textContent = "Getting the cover…";
    try {
      coverBlob = await post({ action: "lookup_cover", release: details.release.mbid }, true);
      if (!(coverBlob instanceof Blob)) throw new Error("MusicBrainz has no front cover for that release.");
      const img = $("lkCoverImg");
      img.src = URL.createObjectURL(coverBlob); img.hidden = false;
      $("lkCoverShow").hidden = true;
      if (ctx.hasArt()) msgEl.textContent = "This song already has album art, so it is left as it is.";
      else { $("lkCoverUse").hidden = false; msgEl.textContent = "The song has no album art yet."; }
    } catch (ex) { msgEl.textContent = ex.message; }
  }
  async function useCover() {
    if (!coverBlob) return;
    $("lkCoverUse").disabled = true;
    try { await ctx.useCover(new File([coverBlob], "cover." + (/png/.test(coverBlob.type) ? "png" : "jpg"), { type: coverBlob.type })); $("lkCoverUse").hidden = true; $("lkCoverMsg").textContent = "Cover added."; }
    catch (ex) { $("lkCoverMsg").textContent = ex.message; }
    finally { $("lkCoverUse").disabled = false; }
  }

  // ---- 4. fill the ticked fields -------------------------------------------------------------------------
  function apply() {
    if (!details) return;
    const f = found(details);
    let n = 0;
    for (const cb of $("lkRows").querySelectorAll("input[type=checkbox]")) {
      if (!cb.checked || cb.disabled) continue;
      if (cb.dataset.field === "_source") { ctx.addNote("Details from MusicBrainz: " + details.recording.url); n++; continue; }
      ctx.set(cb.dataset.field, f[cb.dataset.field]);
      n++;
    }
    ctx.done();
    say(n ? n + (n === 1 ? " field filled in." : " fields filled in.") + " Save the window to keep them." : "Nothing was ticked.");
  }

  $("lkRun").addEventListener("click", run);
  $("lkApply").addEventListener("click", apply);
  $("lkRel").addEventListener("change", () => choose(Number((box.querySelector('input[name="lkPick"]:checked') || { value: 0 }).value), $("lkRel").value));
  $("lkCoverShow").addEventListener("click", showCoverNow);
  $("lkCoverUse").addEventListener("click", useCover);
  window.SwLookup = { init(c) { ctx = c; }, reset };
})();
