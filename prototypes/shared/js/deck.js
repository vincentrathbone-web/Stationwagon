// One playback deck: an <audio> element routed through Web Audio so its volume can follow
// sample-accurate fade curves. Used by the two-deck player (P2) and the live studio (P4).
export const curves = {
  // equal power keeps loudness steady through a crossfade
  in: (n = 64) => Float32Array.from({ length: n }, (_, i) => Math.sin((i / (n - 1)) * Math.PI / 2)),
  out: (n = 64) => Float32Array.from({ length: n }, (_, i) => Math.cos((i / (n - 1)) * Math.PI / 2)),
};

export class Deck {
  constructor(ctx, output, name) {
    this.ctx = ctx;
    this.name = name;
    this.el = new Audio();
    this.el.preload = "auto";
    this.source = ctx.createMediaElementSource(this.el);
    this.gain = ctx.createGain();
    this.gain.gain.value = 0;
    this.source.connect(this.gain).connect(output);
    this.key = null;     // which timeline entry is loaded
    this.entry = null;
    this.busyUntil = 0;  // server time until which this deck is still fading out
  }

  load(url, key, entry) {
    if (this.key === key) return;
    this.key = key;
    this.entry = entry;
    this.el.src = url;
    this.el.load();
  }

  unload() {
    this.el.pause();
    this.key = null;
    this.entry = null;
    this.gain.gain.cancelScheduledValues(this.ctx.currentTime);
    this.gain.gain.value = 0;
  }

  get playing() { return !this.el.paused; }

  /** Seek and play; resolves once playback has started. */
  async playFrom(seconds) {
    if (this.el.readyState < 1) await new Promise((r) => this.el.addEventListener("loadedmetadata", r, { once: true }));
    this.el.currentTime = Math.max(0, seconds);
    await this.el.play();
  }

  /** Ramp volume to `to` (0..1) over `seconds`, starting at AudioContext time `at`. */
  ramp(to, seconds, at = this.ctx.currentTime) {
    const g = this.gain.gain;
    const from = g.value;
    g.cancelScheduledValues(at);
    if (seconds <= 0.01) { g.setValueAtTime(to, at); return; }
    const shape = to > from ? curves.in() : curves.out();
    const values = shape.map((v) => (to > from ? from + (to - from) * v : to + (from - to) * v));
    g.setValueCurveAtTime(values, at, seconds);
  }
}
