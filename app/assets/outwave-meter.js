// The output strip's meter (outwave.js), run inside the sound engine: the peak of each input per
// block of about 10 ms, with the block's start time and length, posted five blocks at a time.
// Inputs: each source's wet signal, then the output itself.
class SwOutMeter extends AudioWorkletProcessor {
  constructor(options) {
    super();
    this.k = options.processorOptions.inputs;
    this.block = Math.round(sampleRate * 0.01);
    this.n = 0; this.start = 0; this.peaks = new Float32Array(this.k); this.batch = [];
  }
  process(inputs) {
    if (this.n === 0) this.start = currentFrame;
    for (let k = 0; k < this.k; k++) {
      const inp = inputs[k];
      if (!inp) continue;
      let p = this.peaks[k];
      for (const ch of inp) for (let i = 0; i < ch.length; i++) { const v = ch[i] < 0 ? -ch[i] : ch[i]; if (v > p) p = v; }
      this.peaks[k] = p;
    }
    this.n += 128;
    if (this.n >= this.block) {
      this.batch.push(this.start / sampleRate, this.n / sampleRate, ...this.peaks);
      this.peaks.fill(0); this.n = 0;
      if (this.batch.length >= 5 * (this.k + 2)) { this.port.postMessage(this.batch); this.batch = []; }
    }
    return true;
  }
}
registerProcessor("sw-outmeter", SwOutMeter);
