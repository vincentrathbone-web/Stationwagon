// Re-levelling the Music library for a new loudness target (round 41). The target is set on Sound & safety
// (round 64); when the Music library opens with songs levelled to another loudness, a quiet line offers to
// re-level them ("Re-level" opens the panel: re-level them now, or only new uploads). Re-levelling makes each
// song again from its kept original in this browser (prep.js), with the mastering it was made with, so only
// its loudness changes, and puts it in place like Song prep's Apply (manage/songprep, action apply). A song
// on air waits and is tried again.
(() => {
  const box = document.getElementById("relevel"), form = document.getElementById("drop");
  if (!box || !form || !window.FormData) return;
  const later = document.getElementById("relevelLater"), list = document.getElementById("relevelList");
  const go = document.getElementById("relevelGo"), skip = document.getElementById("relevelSkip");
  const csrf = form.querySelector('input[name="csrf"]').value;
  const fmt = (v) => Number(v).toFixed(1).replace("-", "−");
  const plural = (n, one, many) => n + " " + (n === 1 ? one : many);
  let songs = [], target = Number(form.dataset.target), running = false;
  try { songs = JSON.parse(box.dataset.songs || "[]"); } catch (_) { /* none */ }

  const describe = () => {
    const ready = songs.filter((s) => s.original), stuck = songs.length - ready.length;
    // old: levelled to the target before round 51, when the encoder left songs 0.26–0.45 dB under it
    const moved = songs.filter((s) => !s.old), old = songs.length - moved.length;
    const from = [...new Set(moved.map((s) => fmt(s.target)))].join(" or ");
    const parts = [];
    if (moved.length) parts.push(plural(moved.length, "song was", "songs were") + " levelled to " + from + " LUFS; the station’s target is now " + fmt(target) + " LUFS.");
    if (old) parts.push(plural(old, "song was", "songs were") + " made with the old encoder, which left " + (old === 1 ? "it" : "them") + " about 0.3 dB under the target; re-levelling makes " + (old === 1 ? "it" : "them") + " again from the original at the exact level.");
    return { ready, text: parts.join(" ") +
      (stuck ? " " + plural(stuck, "of them has", "of them have") + " no original, so it stays as it is until it’s uploaded again." : "") };
  };
  const show = (ask) => {
    if (running) return;
    const d = describe();
    box.hidden = !ask || !songs.length;
    later.hidden = ask || !songs.length;
    document.getElementById("relevelText").textContent = d.text;
    later.querySelector("span").textContent = songs.some((s) => !s.old)
      ? plural(songs.length, "song is", "songs are") + " levelled to another loudness than " + fmt(target) + " LUFS."
      : plural(songs.length, "song was", "songs were") + " made with the old encoder, a little under " + fmt(target) + " LUFS.";
    go.disabled = !d.ready.length;
    go.textContent = d.ready.length ? "Re-level " + plural(d.ready.length, "song", "songs") + " now" : "Nothing to re-level";
  };
  skip.addEventListener("click", () => show(false));
  document.getElementById("relevelOpen").addEventListener("click", () => show(true));
  show(false);

  const row = (title) => {
    const li = document.createElement("li");
    li.innerHTML = '<span class="n"></span><span class="s">Waiting</span><i class="bar"><i></i></i>';
    li.querySelector(".n").textContent = title;
    list.appendChild(li);
    return li;
  };
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const warn = (e) => { if (running) { e.preventDefault(); e.returnValue = ""; } };

  /** Re-level one song; resolves "done", "air" (on air: try again later) or "bad". */
  async function one(s, li) {
    const status = li.querySelector(".s");
    try {
      status.textContent = "Fetching the original";
      const r = await fetch(box.dataset.original + "?id=" + s.id, { credentials: "same-origin" });
      if (!r.ok) throw new Error("the original couldn’t be read (" + r.status + ")");
      const p = await window.SwPrep.prepare(await r.blob(), (stage) => { status.textContent = stage; }, { master: s.master || false, level: true, target });
      if (!p.prepared) throw new Error(p.note || "song prep didn’t run");
      status.textContent = "Putting it in place";
      const data = new FormData();
      const add = (k, v) => data.append(k, v);
      add("csrf", csrf); add("action", "apply"); add("id", String(s.id));
      add("prep_lufs", String(p.info.lufs)); add("prep_outro", JSON.stringify(p.info.outro || []));
      add("prep_mastered", p.info.mastered ? "1" : ""); add("prep_preset", s.preset || "");
      add("prep_source_lufs", String(p.info.lufs_before)); add("prep_gain", String(p.info.gain_db)); add("prep_target", String(p.info.target));
      add("prep_settings", p.info.mastered && s.master ? JSON.stringify(s.master) : "");
      data.append("file", p.file, "relevelled.mp3");
      const j = await fetch(box.dataset.apply, { method: "POST", body: data, headers: { Accept: "application/json" }, credentials: "same-origin" })
        .then((x) => x.json()).catch(() => ({ ok: false, error: "connection lost" }));
      if (!j.ok && /on air/.test(j.error || "")) { status.textContent = "On air: waiting until it isn’t"; return "air"; }
      if (!j.ok) throw new Error(j.error || "the server refused it");
      li.className = "ok";
      status.textContent = "Levelled " + fmt(p.info.lufs_before) + " → " + fmt(p.info.lufs) + " LUFS (" + (p.info.gain_db >= 0 ? "+" : "") + fmt(p.info.gain_db) + " dB)";
      return "done";
    } catch (ex) {
      li.className = "bad";
      status.textContent = "Not re-levelled: " + ex.message;
      return "bad";
    }
  }

  go.addEventListener("click", async () => {
    if (running || !window.SwPrep) return;
    const todo = songs.filter((s) => s.original);
    running = true; go.disabled = true; skip.disabled = true;
    window.addEventListener("beforeunload", warn);
    list.hidden = false; list.innerHTML = "";
    const rows = new Map(todo.map((s) => [s, row(s.title)]));
    let wait = [], done = 0;
    for (const s of todo) { const r = await one(s, rows.get(s)); if (r === "air") wait.push(s); else if (r === "done") done++; }
    // songs on air: try again every 15 s, for up to 15 minutes
    for (let i = 0; wait.length && i < 60; i++) {
      await sleep(15000);
      const again = [];
      for (const s of wait) { const r = await one(s, rows.get(s)); if (r === "air") again.push(s); else if (r === "done") done++; }
      wait = again;
    }
    running = false;
    window.removeEventListener("beforeunload", warn);
    document.getElementById("relevelText").textContent = "Re-levelled " + plural(done, "song", "songs") + " to " + fmt(target) + " LUFS" +
      (done < todo.length ? "; " + plural(todo.length - done, "wasn’t", "weren’t") + " (see below)." : ".");
    skip.disabled = false;
    if (done === todo.length) setTimeout(() => location.reload(), 1500);
  });
  // for tests
  window.SwRelevel = { state: () => ({ songs, target, running, ask: !box.hidden, later: !later.hidden }) };
})();
