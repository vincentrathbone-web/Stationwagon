// Server clock for the browser. Every timeline time is server time, so players must know how
// far this device's clock is from the server's. Takes the sample with the shortest round trip.
export class ServerClock {
  constructor(url) {
    this.url = url;
    this.offsetMs = 0;
    this.rttMs = Infinity;
    this.last = null; // last timeline response
  }

  local() { return performance.timeOrigin + performance.now(); }

  /** Server time in seconds (float). */
  now() { return (this.local() + this.offsetMs) / 1000; }

  async fetchTimeline() {
    const t0 = this.local();
    const res = await fetch(this.url + (this.url.includes("?") ? "&" : "?") + "t=" + Math.round(t0), { cache: "no-store" });
    const t1 = this.local();
    if (!res.ok) throw new Error(`Timeline request failed (HTTP ${res.status})`);
    const json = await res.json();
    // keep the last 10 samples and trust the one with the shortest round trip
    this.samples = [...(this.samples || []), { rtt: t1 - t0, offset: json.server_now * 1000 - (t0 + t1) / 2 }].slice(-10);
    const best = this.samples.reduce((a, b) => (b.rtt < a.rtt ? b : a));
    this.offsetMs = best.offset;
    this.rttMs = best.rtt;
    this.last = json;
    return json;
  }

  async sync(rounds = 4) {
    for (let i = 0; i < rounds; i++) await this.fetchTimeline();
    return this;
  }
}

export const fmt = (s) => {
  if (!isFinite(s)) return "–";
  const neg = s < 0; s = Math.abs(s);
  const m = Math.floor(s / 60), sec = Math.floor(s % 60);
  return (neg ? "-" : "") + m + ":" + String(sec).padStart(2, "0");
};

export const clockText = (unixSeconds) =>
  new Date(unixSeconds * 1000).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23" }) +
  "." + String(Math.floor((unixSeconds % 1) * 10));

/** The entry covering server time t (songs and live blocks). */
export function entryAt(entries, t) {
  return entries.find((e) => e.start <= t && (t < e.end || (e.type === "live" && e.open))) || null;
}

export function getKey() { try { return localStorage.getItem("sw-key") || "stationwagon-poc"; } catch { return "stationwagon-poc"; } }
export function setKey(k) { try { localStorage.setItem("sw-key", k); } catch {} }

export async function control(action, fields = {}) {
  const body = new URLSearchParams({ action, ...fields });
  const res = await fetch(new URL("../api/control.php", location.href), { method: "POST", body, headers: { "X-Key": getKey() } });
  const json = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
  if (!res.ok) throw new Error(json.error || `HTTP ${res.status}`);
  return json;
}
