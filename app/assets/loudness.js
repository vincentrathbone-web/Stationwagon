// The loudness target slider on Sound & safety (it was on the Music library until round 64): a drag snaps to the
// usual levels (Apple Music, Spotify, loud, very loud), the arrow keys step half a dB past them, the value shows
// as it moves, and the slider dims while loudness normalization is off. Saved with the rest of the form.
(() => {
  const el = document.getElementById("f-prep_target");
  if (!el) return;
  const box = document.getElementById("loud"), out = box.querySelector("output"), level = document.getElementById("f-prep_level");
  const fmt = (v) => Number(v).toFixed(1).replace("-", "−");
  const SNAP = [-16, -14, -11, -9];
  let dragging = false;
  el.addEventListener("pointerdown", () => { dragging = true; });
  ["pointerup", "pointercancel"].forEach((t) => el.addEventListener(t, () => { dragging = false; }));
  el.addEventListener("input", () => {
    // only a drag snaps: the arrow keys step half a dB at a time, past the marks too
    const near = dragging && SNAP.find((v) => Math.abs(v - Number(el.value)) <= 0.5 && Number(el.value) !== v);
    if (near !== undefined && near !== false) el.value = String(near);
    out.textContent = fmt(el.value) + " LUFS";
  });
  if (level) {
    const dim = () => box.toggleAttribute("data-off", level.value !== "1");
    level.addEventListener("change", dim);
    dim();
  }
})();
