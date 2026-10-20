// The station's fade shapes (Sound & safety), shared by the player, its look-ahead, the studio
// and the curve preview on the settings screen. x runs 0 → 1 over the fade; in(x) is the gain of
// the song coming in, out(x) of the one going out.
//   equal_power  sin / cos: the loudness stays even through the blend (the default)
//   linear       x / 1 − x: the gains add up to 1, so the middle dips about 3 dB
//   s_curve      raised cosine: holds both songs near where they were, then blends quickly
window.SwFade = (() => {
  const SHAPES = {
    equal_power: { in: (x) => Math.sin(x * Math.PI / 2), out: (x) => Math.cos(x * Math.PI / 2) },
    linear: { in: (x) => x, out: (x) => 1 - x },
    s_curve: { in: (x) => 0.5 - 0.5 * Math.cos(Math.PI * x), out: (x) => 0.5 + 0.5 * Math.cos(Math.PI * x) },
  };
  const shape = (name) => SHAPES[name] || SHAPES.equal_power;
  const clamp = (x) => (x <= 0 ? 0 : x >= 1 ? 1 : x);
  return {
    shapes: Object.keys(SHAPES),
    in: (name, x) => shape(name).in(clamp(x)),
    out: (name, x) => shape(name).out(clamp(x)),
    /** A gain curve from → to in n points, for AudioParam.setValueCurveAtTime. */
    curve(name, from, to, n = 64) {
      const s = shape(name);
      return Float32Array.from({ length: n }, (_, i) => {
        const x = i / (n - 1);
        return to > from ? from + (to - from) * s.in(x) : to + (from - to) * s.out(x);
      });
    },
  };
})();
