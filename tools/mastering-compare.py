"""Compare the mastering model's output with Audition's exports (mastering-ref/, gitignored).

    node tools/mastering-fit.mjs both model.f32            (or multiband / mastering)
    python tools/mastering-compare.py model.f32 both [songs-only]

Needs numpy, scipy and soundfile. The exports are the test signals (tools/mastering-signals.mjs)
and four songs, from Audition as 24-bit WAV: dry.wav (no effects), mutiband.wav (Broadcast1 only)
and both.wav (Broadcast1 + Master1). OFF is where the test signals start in them, and `songs` where
each song is (both found by cross-correlation, see HANDOVER.md, round 34); a new set of exports
needs both measured again. Prints the differences (model - Audition) in dB: the test tones by level,
the sweep, the level jumps, and for each song loudness, peaks, width, each band moment to moment
(mean ± spread) and the third-octave balance.
"""
import os
import sys, json, numpy as np, soundfile as sf
from scipy.signal import butter, sosfilt
D = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'mastering-ref'); R = 44100; OFF = -65438
J = json.load(open(os.path.join(D, '00 Test signals.json')))
model = np.fromfile(sys.argv[1], dtype=np.float32).reshape(-1, 2)
ref = sf.read(os.path.join(D, 'mutiband.wav' if sys.argv[2] == 'multiband' else 'both.wav'), dtype='float32')[0][:len(model)]
dry = sf.read(os.path.join(D, 'mutiband.wav' if sys.argv[2] == 'mastering' else 'dry.wav'), dtype='float32')[0][:len(model)]
songsOnly = len(sys.argv) > 3
db = lambda v: 20 * np.log10(max(float(v), 1e-12))
rms = lambda y: np.sqrt((y.astype(np.float64) ** 2).mean())
def seg(x, p, a=0.3, b=0.9):
    s = int(p['at'] * R) + OFF
    return x[s + int(a * p['sec'] * R): s + int(b * p['sec'] * R)]
if not songsOnly:
    print('tones (model - Audition, dB), by input level -45..-6:')
    for hz in [60, 400, 3000, 12000]:
        ps = [p for p in J['parts'] if p.get('hz') == hz and p['name'].startswith('tone')]
        print(f'  {hz:5d}', ' '.join(f'{db(rms(seg(model, p))) - db(rms(seg(ref, p))):+5.1f}' for p in ps))
    print('  pink', ' '.join(f'{db(rms(seg(model, p))) - db(rms(seg(ref, p))):+5.1f}' for p in J['parts'] if p['name'].startswith('pink')))
    print('  stereo', ' '.join(f"{p['name'][:4]} L{db(rms(seg(model, p)[:,0])) - db(rms(seg(ref, p)[:,0])):+5.1f} R{db(rms(seg(model, p)[:,1])) - db(rms(seg(ref, p)[:,1])):+5.1f}" for p in J['parts'] if p['name'].endswith('only -24')))
    p = [p for p in J['parts'] if p['name'].startswith('sweep')][0]; s = int(p['at'] * R) + OFF
    out = []
    for hz in [40, 100, 137, 300, 1147, 3000, 5000, 6910, 10000, 16000]:
        t = 12 * np.log(hz / 20) / np.log(1000); i = s + int(t * R); w = int(0.02 * R) if hz > 200 else int(0.08 * R)
        out.append(f'{hz}:{db(rms(model[i - w:i + w])) - db(rms(ref[i - w:i + w])):+.2f}')
    print('  sweep', ' '.join(out))
    print('  jumps (gain over time, model - Audition, 10 ms steps from the jump):')
    for p in [p for p in J['parts'] if p['name'].startswith('jump')]:
        s = int(p['at'] * R) + OFF; hz = p['hz']; w = max(int(R / hz * 2), 64)
        def g(x, t0, t1): return [db(rms(x[s + int(t * R): s + int(t * R) + w])) for t in np.arange(t0, t1, 0.01)]
        up = np.array(g(model, 1.0, 1.15)) - np.array(g(ref, 1.0, 1.15)); dn = np.array(g(model, 2.0, 2.3)) - np.array(g(ref, 2.0, 2.3))
        print(f'    {hz:5d} up', ' '.join(f'{v:+.1f}' for v in up[:8]), '| down', ' '.join(f'{v:+.1f}' for v in dn[:12:2]))
songs = [(124.8, 354.8), (360.0, 695.9), (713.4, 816.1), (834.2, 1018.6)]
def kw(x):
    s1 = np.array([[1.53512485958697, -2.69169618940638, 1.19839281085285, 1, -1.69065929318241, 0.73248077421585]])
    s2 = np.array([[1.0, -2.0, 1.0, 1, -1.99004745483398, 0.99007225036621]])
    return sosfilt(s2, sosfilt(s1, x, axis=0), axis=0)
def lufs(x):
    y = kw(x); b = int(0.4 * R); h = int(0.1 * R)
    c = np.cumsum(np.concatenate([[0], (y ** 2).sum(1)])); ms = (c[b::h] - c[:-b:h][:len(c[b::h])]) / b
    l = -0.691 + 10 * np.log10(ms + 1e-20); ms = ms[l > -70]
    rel = -0.691 + 10 * np.log10(ms.mean()) - 10
    return -0.691 + 10 * np.log10(ms[(-0.691 + 10 * np.log10(ms)) > rel].mean())
thirds = [25 * 2 ** (k / 3) for k in range(0, 30)]
tot = []
for k, (a, b) in enumerate(songs):
    m = model[int(a * R):int(b * R)]; r = ref[int(a * R):int(b * R)]
    M = lambda y: (y[:, 0] + y[:, 1]) / 2; S = lambda y: (y[:, 0] - y[:, 1]) / 2
    q = lambda y, pc: db(np.percentile(np.abs(y[::3]), pc))
    line = f'song {k + 1}: p99.9 {q(m, 99.9) - q(r, 99.9):+.2f} p99.99 {q(m, 99.99) - q(r, 99.99):+.2f}  LUFS {lufs(m) - lufs(r):+.2f}  peak {db(np.abs(m).max()):.2f}/{db(np.abs(r).max()):.2f}  crest {(db(np.abs(m).max()) - db(rms(m))) - (db(np.abs(r).max()) - db(rms(r))):+.2f}  S-M {(db(rms(S(m))) - db(rms(M(m)))) - (db(rms(S(r))) - db(rms(M(r)))):+.2f}'
    # short-term level error per band (100 ms), over blocks that aren't near-silent
    errs = []
    for lo, hi in [(20, 137), (137, 1147), (1147, 6910), (6910, 20000)]:
        sos = butter(4, [lo, min(hi, 21000)], 'bandpass', fs=R, output='sos')
        ym = sosfilt(sos, M(m)); yr = sosfilt(sos, M(r)); n = int(0.1 * R); cnt = len(ym) // n
        em = 10 * np.log10((ym[:cnt * n].reshape(cnt, n) ** 2).mean(1) + 1e-20); er = 10 * np.log10((yr[:cnt * n].reshape(cnt, n) ** 2).mean(1) + 1e-20)
        ok = er > er.max() - 40; d = em[ok] - er[ok]
        errs.append(f'{d.mean():+.2f}±{d.std():.2f}'); tot.append(d.std())
    line += '  bands ' + ' '.join(errs)
    # long-term third-octave spectrum difference
    fm = np.abs(np.fft.rfft(M(m)[:2 ** 22] if len(m) > 2 ** 22 else M(m))) ** 2; fr = np.abs(np.fft.rfft(M(r)[:len(M(m)[:2 ** 22])])) ** 2
    f = np.fft.rfftfreq(min(len(m), 2 ** 22), 1 / R)
    sp = []
    for c in thirds:
        sel = (f >= c / 2 ** (1 / 6)) & (f < c * 2 ** (1 / 6))
        sp.append(10 * np.log10(fm[sel].sum() / fr[sel].sum()))
    line += '\n   1/3 oct ' + ' '.join(f'{v:+.1f}' for v in sp[::2])
    print(line)
print(f'mean short-term band error spread: {np.mean(tot):.3f} dB')
