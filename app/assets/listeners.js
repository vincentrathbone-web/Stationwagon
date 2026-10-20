// Listener count: while a player plays it pings api/listen.php once a minute with a random id made
// when Listen is pressed (not kept anywhere: no cookie, no storage), and a last "stop" ping when it
// stops or the page closes. The station counts the ids it heard in the last 90 s (src/Listeners.php).
//   const ping = SwListeners.start(base, "player", (n) => show(n));  …  ping.stop();
window.SwListeners = (() => {
  const EVERY_MS = 60000;
  const randomId = () => [...crypto.getRandomValues(new Uint8Array(8))].map((b) => b.toString(16).padStart(2, "0")).join("");

  function start(base, source, onCount) {
    const url = base + "api/listen.php", sid = randomId();
    let timer = 0, stopped = false;
    const send = async () => {
      try {
        const r = await fetch(url, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ sid, source }), cache: "no-store", keepalive: true });
        const j = await r.json();
        if (!stopped && onCount && typeof j.listening === "number") onCount(j.listening);
      } catch (_) { /* the next ping tries again */ }
    };
    const bye = () => {
      const body = JSON.stringify({ sid, source, stop: true });
      if (!(navigator.sendBeacon && navigator.sendBeacon(url, body))) fetch(url, { method: "POST", body, keepalive: true }).catch(() => {});
    };
    const stop = () => {
      if (stopped) return;
      stopped = true; clearInterval(timer);
      window.removeEventListener("pagehide", stop);
      bye();
    };
    send();
    timer = setInterval(send, EVERY_MS);
    window.addEventListener("pagehide", stop); // the tab closes or navigates away
    return { sid, stop, get stopped() { return stopped; } };
  }

  return { start };
})();
