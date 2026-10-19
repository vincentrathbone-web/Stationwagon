// Splits an MP3 byte stream into whole frames, so live pieces always start and end on a frame.
const BR_V1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
const BR_V2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0];
const RATES = { 3: [44100, 48000, 32000], 2: [22050, 24000, 16000], 0: [11025, 12000, 8000] };

export class Mp3FrameSplitter {
  constructor() { this.buf = new Uint8Array(0); }

  /** Add bytes; returns an array of complete frames { bytes, samples, rate }. */
  push(chunk) {
    if (chunk && chunk.length) {
      const merged = new Uint8Array(this.buf.length + chunk.length);
      merged.set(this.buf); merged.set(chunk, this.buf.length);
      this.buf = merged;
    }
    const frames = [];
    let pos = 0;
    while (pos + 4 <= this.buf.length) {
      const b1 = this.buf[pos], b2 = this.buf[pos + 1], b3 = this.buf[pos + 2];
      const version = (b2 >> 3) & 3, layer = (b2 >> 1) & 3, bi = (b3 >> 4) & 15, ri = (b3 >> 2) & 3;
      if (b1 !== 0xff || (b2 & 0xe0) !== 0xe0 || version === 1 || layer !== 1 || bi === 0 || bi === 15 || ri === 3) { pos++; continue; }
      const kbps = (version === 3 ? BR_V1 : BR_V2)[bi], rate = RATES[version][ri], pad = (b3 >> 1) & 1;
      const len = Math.floor(((version === 3 ? 144 : 72) * kbps * 1000) / rate) + pad;
      if (pos + len > this.buf.length) break; // wait for the rest of this frame
      frames.push({ bytes: this.buf.slice(pos, pos + len), samples: version === 3 ? 1152 : 576, rate });
      pos += len;
    }
    this.buf = this.buf.slice(pos);
    return frames;
  }
}
