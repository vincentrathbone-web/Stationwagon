// The sign-in page in Stationwagon Studio for Android: Android's Credential Manager offers the saved
// sign-in (Google Password Manager) and fills the form in; after signing in by typing, the app
// offers to save it. Browsers and the Windows app have their own password managers: nothing here.
// Round 81: inside the Windows app (window.__TAURI__) or the Android app (Capacitor's StationApp) the card also gets a station picker above the email:
// the stations saved in the app (the one open is picked), and "Add a station…" with an address box. Picking one
// opens that station's own sign-in page (the app switches; nothing is sent to the station's page).
(() => {
  const T = window.__TAURI__ && window.__TAURI__.core ? window.__TAURI__.core : null;
  const A = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.StationApp;
  // the same three calls in both apps: { current, stations: [{ base, name }] }, open a saved one, find and add one
  const api = T ? { stations: () => T.invoke("stations"), switchStation: (base) => T.invoke("switch_station", { base }), addStation: (address) => T.invoke("add_station", { address }) }
    : A && A.stations && A.switchStation && A.addStation ? { stations: () => A.stations(), switchStation: (base) => A.switchStation({ base }), addStation: (address) => A.addStation({ address }) } : null;
  const why = (e) => (e && e.message) || String(e);
  const form = document.querySelector("main form");
  if (!api || !form) return;
  const ADD = "\u0000add";
  const wrap = document.createElement("div");
  wrap.className = "field stpick";
  const label = document.createElement("label");
  label.className = "lbl"; label.htmlFor = "stationPick"; label.textContent = "Station";
  const sel = document.createElement("select");
  sel.id = "stationPick"; // no name: it is never sent with the sign-in
  const addBox = document.createElement("div");
  addBox.className = "stadd"; addBox.hidden = true;
  const addr = document.createElement("input");
  addr.type = "text"; addr.placeholder = "The station's address, e.g. https://example.com/radio"; addr.setAttribute("aria-label", "The station's address");
  addr.autocomplete = "off"; addr.spellcheck = false;
  const go = document.createElement("button");
  go.type = "button"; go.className = "btn"; go.textContent = "Find and open";
  const note = document.createElement("p");
  note.className = "hint"; note.setAttribute("role", "status");
  addBox.append(addr, go);
  wrap.append(label, sel, addBox, note);
  form.insertBefore(wrap, form.querySelector(".grid"));
  let current = null;
  const fill = (list) => {
    sel.textContent = "";
    current = list.current;
    for (const s of list.stations) { const o = document.createElement("option"); o.value = s.base; o.textContent = s.name || s.base; sel.append(o); }
    const o = document.createElement("option"); o.value = ADD; o.textContent = "Add a station…"; sel.append(o);
    if (current) sel.value = current;
  };
  const busy = (on) => { sel.disabled = on; go.disabled = on; };
  api.stations().then(fill, () => { wrap.hidden = true; });
  sel.addEventListener("change", () => {
    note.textContent = "";
    if (sel.value === ADD) { addBox.hidden = false; addr.focus(); return; }
    addBox.hidden = true;
    if (sel.value === current) return;
    busy(true); note.textContent = "Opening the station…";
    api.switchStation(sel.value).catch((e) => { busy(false); note.textContent = why(e); sel.value = current; });
  });
  const add = async () => {
    const a = addr.value.trim();
    if (!a) { note.textContent = "Type the station's address first."; return; }
    busy(true); note.textContent = "Looking for the station…";
    try {
      const r = await api.addStation(a);
      busy(false);
      if (!r.found) { note.textContent = r.message || "No station found at that address."; }
    } catch (e) { busy(false); note.textContent = why(e); }
  };
  go.addEventListener("click", add);
  addr.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); add(); } });
})();
(() => {
  const App = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.StationApp;
  const f = document.querySelector("main form");
  if (!App || !App.savedSignIn || !f) return;
  // the app offers to save it once the next page shows the sign-in worked
  f.addEventListener("submit", () => { App.signingIn({ email: f.email.value, password: f.password.value }).catch(() => {}); });
  if (f.querySelector(".alert")) return; // a wrong password: no offer of the saved one on top
  App.savedSignIn().then((c) => {
    if (!c || !c.email) return;
    f.email.value = c.email;
    f.password.value = c.password;
    f.querySelector("button[type=submit]").focus();
  }, () => {});
})();
