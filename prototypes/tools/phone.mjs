// Tiny Chrome DevTools client for the phone (via `adb forward tcp:9222 localabstract:chrome_devtools_remote`).
//   node phone.mjs eval "<js expression>"        evaluate in the first page tab (as a user gesture)
//   node phone.mjs nav "<url>"                    navigate the first page tab
//   node phone.mjs offline <seconds>             cut the page off the network for a while
const [cmd, arg] = process.argv.slice(2);
const pages = await (await fetch("http://localhost:9222/json")).json();
const page = pages.find((p) => p.type === "page");
if (!page) { console.error("no page tab"); process.exit(1); }

let id = 0;
const pending = new Map();
async function connect(url) {
  const ws = new WebSocket(url);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
  return (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
}

const call = await connect(page.webSocketDebuggerUrl);
if (cmd === "offline") {
  // Cut the page off the network for <seconds> (simulates pulling the cable mid-show).
  // The emulation only lasts while this script stays attached.
  await call("Network.enable");
  await call("Network.emulateNetworkConditions", { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  console.log(`offline for ${arg} s from ${new Date().toLocaleTimeString()}`);
  await new Promise((ok) => setTimeout(ok, Number(arg) * 1000));
  await call("Network.emulateNetworkConditions", { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  console.log(`back online at ${new Date().toLocaleTimeString()}`);
} else if (cmd === "nav") {
  const r = await call("Page.navigate", { url: arg });
  console.log(JSON.stringify(r.result || r.error));
} else {
  const r = await call("Runtime.evaluate", { expression: arg, awaitPromise: true, returnByValue: true, userGesture: true });
  const v = r.result?.result;
  console.log(r.result?.exceptionDetails ? "EXCEPTION " + JSON.stringify(r.result.exceptionDetails.exception?.description || r.result.exceptionDetails.text)
    : typeof v?.value === "string" ? v.value : JSON.stringify(v?.value ?? v));
}
process.exit(0);
