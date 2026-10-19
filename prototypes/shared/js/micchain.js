// Broadcast voice chain built from Web Audio nodes:
// mic -> input gain -> low cut -> gate (AudioWorklet) -> presence EQ -> compressor -> makeup -> limiter -> out
// plus a ducker that lowers a music bus while the (gated) voice is active.

const GATE_WORKLET = `
class SwGate extends AudioWorkletProcessor {
  static get parameterDescriptors() {
    return [
      { name: "threshold", defaultValue: -45, minValue: -100, maxValue: 0 },
      { name: "attack", defaultValue: 0.003, minValue: 0.0005, maxValue: 0.1 },
      { name: "release", defaultValue: 0.2, minValue: 0.01, maxValue: 2 },
      { name: "hold", defaultValue: 0.12, minValue: 0, maxValue: 1 },
      { name: "floor", defaultValue: -60, minValue: -100, maxValue: 0 },
    ];
  }
  constructor() { super(); this.env = 0; this.gain = 0; this.holdLeft = 0; this.tick = 0; }
  process(inputs, outputs, p) {
    const input = inputs[0], output = outputs[0];
    if (!input || !input.length) return true;
    const n = input[0].length;
    const thr = Math.pow(10, p.threshold[0] / 20), floor = Math.pow(10, p.floor[0] / 20);
    const att = Math.exp(-1 / (p.attack[0] * sampleRate)), rel = Math.exp(-1 / (p.release[0] * sampleRate));
    const envRel = Math.exp(-1 / (0.05 * sampleRate));
    const hold = p.hold[0] * sampleRate;
    for (let i = 0; i < n; i++) {
      let peak = 0;
      for (let c = 0; c < input.length; c++) peak = Math.max(peak, Math.abs(input[c][i]));
      this.env = peak > this.env ? peak : this.env * envRel + peak * (1 - envRel);
      let target;
      if (this.env >= thr) { this.holdLeft = hold; target = 1; }
      else if (this.holdLeft > 0) { this.holdLeft--; target = 1; }
      else target = floor;
      const coef = target > this.gain ? att : rel;
      this.gain = target + (this.gain - target) * coef;
      for (let c = 0; c < output.length; c++) output[c][i] = (input[c] ? input[c][i] : input[0][i]) * this.gain;
    }
    if (++this.tick % 16 === 0) this.port.postMessage({ open: this.gain > 0.5, gain: this.gain });
    return true;
  }
}
registerProcessor("sw-gate", SwGate);`;

const loaded = new WeakSet();
export async function loadWorklets(ctx) {
  if (loaded.has(ctx)) return;
  const url = URL.createObjectURL(new Blob([GATE_WORKLET], { type: "text/javascript" }));
  await ctx.audioWorklet.addModule(url);
  loaded.add(ctx);
}

export const PRESETS = {
  Preacher:     { gain: 8, lowcut: 80,  gateThreshold: -45, gateRelease: 0.2,  presence: 2, compThreshold: -18, compRatio: 3, makeup: 3, ceiling: -1, duckDepth: -12, duckRelease: 0.8 },
  "Music show": { gain: 6, lowcut: 60,  gateThreshold: -55, gateRelease: 0.3,  presence: 1, compThreshold: -14, compRatio: 2, makeup: 2, ceiling: -1, duckDepth: -8,  duckRelease: 0.6 },
  Interview:    { gain: 8, lowcut: 100, gateThreshold: -50, gateRelease: 0.15, presence: 3, compThreshold: -20, compRatio: 4, makeup: 4, ceiling: -1, duckDepth: -15, duckRelease: 0.9 },
};

const db = (v) => Math.pow(10, v / 20);

export async function createMicChain(ctx, stream, settings = PRESETS.Preacher) {
  await loadWorklets(ctx);
  const s = { ...PRESETS.Preacher, ...settings };
  const enabled = { input: true, gate: true, eq: true, comp: true, limiter: true, duck: true };

  const source = ctx.createMediaStreamSource(stream);
  const input = ctx.createGain();
  const lowcut = ctx.createBiquadFilter(); lowcut.type = "highpass"; lowcut.Q.value = 0.707;
  const gate = new AudioWorkletNode(ctx, "sw-gate", { outputChannelCount: [1] });
  const presence = ctx.createBiquadFilter(); presence.type = "peaking"; presence.frequency.value = 3200; presence.Q.value = 0.9;
  const comp = ctx.createDynamicsCompressor(); comp.knee.value = 6; comp.attack.value = 0.005; comp.release.value = 0.15;
  const makeup = ctx.createGain();
  const limiter = ctx.createDynamicsCompressor(); limiter.knee.value = 0; limiter.ratio.value = 20; limiter.attack.value = 0.001; limiter.release.value = 0.05;
  const output = ctx.createGain();
  const preAnalyser = ctx.createAnalyser(); preAnalyser.fftSize = 1024;
  const postAnalyser = ctx.createAnalyser(); postAnalyser.fftSize = 1024;

  source.connect(input).connect(lowcut).connect(gate).connect(presence).connect(comp).connect(makeup).connect(limiter).connect(output);
  input.connect(preAnalyser);
  output.connect(postAnalyser);

  let gateOpen = false;
  gate.port.onmessage = (e) => (gateOpen = e.data.open);

  function apply() {
    const t = ctx.currentTime;
    input.gain.setTargetAtTime(enabled.input ? db(s.gain) : 1, t, 0.02);
    lowcut.frequency.setTargetAtTime(enabled.eq ? s.lowcut : 10, t, 0.02);
    presence.gain.setTargetAtTime(enabled.eq ? s.presence : 0, t, 0.02);
    gate.parameters.get("threshold").setValueAtTime(enabled.gate ? s.gateThreshold : -100, t);
    gate.parameters.get("release").setValueAtTime(s.gateRelease, t);
    comp.threshold.setValueAtTime(enabled.comp ? s.compThreshold : 0, t);
    comp.ratio.setValueAtTime(enabled.comp ? s.compRatio : 1, t);
    makeup.gain.setTargetAtTime(enabled.comp ? db(s.makeup) : 1, t, 0.02);
    limiter.threshold.setValueAtTime(enabled.limiter ? s.ceiling : 0, t);
    limiter.ratio.setValueAtTime(enabled.limiter ? 20 : 1, t);
  }
  apply();

  const level = (an) => {
    const buf = new Float32Array(an.fftSize);
    an.getFloatTimeDomainData(buf);
    let peak = 0; for (const v of buf) peak = Math.max(peak, Math.abs(v));
    return peak > 0 ? 20 * Math.log10(peak) : -100;
  };

  return {
    input, output, settings: s, enabled,
    set(name, value) { s[name] = value; apply(); },
    setEnabled(name, on) { enabled[name] = on; apply(); },
    usePreset(name) { Object.assign(s, PRESETS[name]); apply(); },
    meters() {
      return { inDb: level(preAnalyser), outDb: level(postAnalyser), gateOpen: !enabled.gate || gateOpen, reductionDb: comp.reduction };
    },
    stop() { stream.getTracks().forEach((t) => t.stop()); source.disconnect(); },
  };
}

/** Lowers `musicGain` while the chain's voice is active. Call stop() to remove. */
export function createDucker(ctx, musicGain, chain, isActive = () => true) {
  let ducked = false;
  const timer = setInterval(() => {
    const m = chain.meters();
    const speaking = chain.enabled.duck && isActive() && m.gateOpen && m.outDb > -42;
    const t = ctx.currentTime;
    if (speaking && !ducked) { musicGain.gain.cancelScheduledValues(t); musicGain.gain.setTargetAtTime(db(chain.settings.duckDepth), t, 0.06); ducked = true; }
    if (!speaking && ducked) { musicGain.gain.cancelScheduledValues(t); musicGain.gain.setTargetAtTime(1, t, chain.settings.duckRelease / 3); ducked = false; }
  }, 30);
  return { get ducked() { return ducked; }, stop() { clearInterval(timer); } };
}

export async function listInputs() {
  const devices = await navigator.mediaDevices.enumerateDevices();
  return devices.filter((d) => d.kind === "audioinput");
}

export function getMic(deviceId) {
  return navigator.mediaDevices.getUserMedia({
    audio: {
      deviceId: deviceId ? { exact: deviceId } : undefined,
      // our own chain does the processing; browser voice-call processing would fight it
      echoCancellation: false, noiseSuppression: false, autoGainControl: false,
      channelCount: 1,
    },
  });
}
