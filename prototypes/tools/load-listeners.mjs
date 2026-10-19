// Simulated HLS listeners: each one fetches the playlist every <interval> s, like hls.js does.
//   node prototypes/tools/load-listeners.mjs <playlist-url> [listeners=100] [seconds=60] [interval=6]
// Prints how many requests Cloudflare answered from cache and how slow the misses were.
const [url, n = 100, seconds = 60, interval = 6] = process.argv.slice(2);
if (!url) { console.error("usage: node load-listeners.mjs <playlist-url> [listeners] [seconds] [interval]"); process.exit(1); }
const end = Date.now() + seconds * 1000;
const stats = { total: 0, failed: 0, status: {}, ms: { HIT: [], MISS: [], EXPIRED: [], other: [] } };

async function listener(i) {
  await new Promise((r) => setTimeout(r, (i / n) * interval * 1000)); // spread the start
  while (Date.now() < end) {
    const t0 = performance.now();
    try {
      const res = await fetch(url);
      await res.text();
      const cf = res.headers.get("cf-cache-status") || "none";
      stats.status[cf] = (stats.status[cf] || 0) + 1;
      (stats.ms[cf] || stats.ms.other).push(performance.now() - t0);
      if (!res.ok) stats.failed++;
    } catch { stats.failed++; }
    stats.total++;
    await new Promise((r) => setTimeout(r, interval * 1000));
  }
}

const pct = (a, p) => (a.length ? a.slice().sort((x, y) => x - y)[Math.floor((a.length - 1) * p)].toFixed(0) + " ms" : "–");
await Promise.all(Array.from({ length: Number(n) }, (_, i) => listener(i)));
console.log(`${n} listeners, ${seconds} s, every ${interval} s: ${stats.total} requests, ${stats.failed} failed`);
console.log("Cloudflare:", JSON.stringify(stats.status));
for (const k of Object.keys(stats.ms)) if (stats.ms[k].length) console.log(`  ${k}: median ${pct(stats.ms[k], 0.5)}, 95% ${pct(stats.ms[k], 0.95)}`);
