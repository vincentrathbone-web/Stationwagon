// The studio's MP3 encoder, off the page's main thread so a busy page can't hold up the pieces.
// In, in order: { init: { gen, rate, kbps, channels } } for each show (channels 2, or 1 for the
// podcast recorder's mic track, which sends only `l`); { pcm: { gen, l, r } } (Float32Array
// left and right, sent as transferables); { flush: { gen } } at End show. Out, in the same order:
// { gen, frames: [{ bytes, samples }], final } (whole MP3 frames only, as the pieces need), or
// { error }. `gen` tells the page's answers for an old show from the current one.
importScripts("vendor/lame.min.js");

const BR_V1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
const BR_V2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0];
const RATES = { 3: [44100, 48000, 32000], 2: [22050, 24000, 16000], 0: [11025, 12000, 8000] };

/** Cuts an MP3 byte stream into whole frames (from the POC); keeps a partial frame for next time. */
class FrameSplitter {
  constructor() { this.buf = new Uint8Array(0); }
  push(chunk) {
    if (chunk && chunk.length) { const m = new Uint8Array(this.buf.length + chunk.length); m.set(this.buf); m.set(chunk, this.buf.length); this.buf = m; }
    const frames = []; let pos = 0;
    while (pos + 4 <= this.buf.length) {
      const b1 = this.buf[pos], b2 = this.buf[pos + 1], b3 = this.buf[pos + 2];
      const version = (b2 >> 3) & 3, layer = (b2 >> 1) & 3, bi = (b3 >> 4) & 15, ri = (b3 >> 2) & 3;
      if (b1 !== 0xff || (b2 & 0xe0) !== 0xe0 || version === 1 || layer !== 1 || bi === 0 || bi === 15 || ri === 3) { pos++; continue; }
      const kbps = (version === 3 ? BR_V1 : BR_V2)[bi], rate = RATES[version][ri], pad = (b3 >> 1) & 1;
      const len = Math.floor(((version === 3 ? 144 : 72) * kbps * 1000) / rate) + pad;
      if (pos + len > this.buf.length) break;
      frames.push({ bytes: this.buf.slice(pos, pos + len), samples: version === 3 ? 1152 : 576 });
      pos += len;
    }
    this.buf = this.buf.slice(pos);
    return frames;
  }
}

const toInt16 = (f) => { const o = new Int16Array(f.length); for (let i = 0; i < f.length; i++) { const s = Math.max(-1, Math.min(1, f[i])); o[i] = s < 0 ? s * 0x8000 : s * 0x7fff; } return o; };
let encoder = null, splitter = null, gen = -1, channels = 2;
const send = (frames, final) => self.postMessage({ gen, frames, final }, frames.map((f) => f.bytes.buffer));

self.onmessage = (e) => {
  try {
    const m = e.data;
    if (m.init) {
      gen = m.init.gen;
      channels = m.init.channels === 1 ? 1 : 2;
      encoder = new lamejs.Mp3Encoder(channels, m.init.rate, m.init.kbps);
      splitter = new FrameSplitter();
    } else if (m.pcm && m.pcm.gen === gen && encoder) {
      const mp3 = channels === 1 ? encoder.encodeBuffer(toInt16(m.pcm.l)) : encoder.encodeBuffer(toInt16(m.pcm.l), toInt16(m.pcm.r));
      send(splitter.push(new Uint8Array(mp3.buffer, mp3.byteOffset, mp3.length)), false);
    } else if (m.flush && m.flush.gen === gen && encoder) {
      send(splitter.push(new Uint8Array(encoder.flush())), true);
      encoder = null;
    }
  } catch (ex) {
    self.postMessage({ error: String((ex && ex.message) || ex) });
  }
};
self.postMessage({ ready: true });
