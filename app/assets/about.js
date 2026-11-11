// The About screen (round 68). Inside the Windows app (window.__TAURI__) it shows the app's own version and a
// Check for updates button: the app finds a newer version, shows what it brings, and installs it on request
// (the app refuses while you are on air, then installs the next time it opens). In a browser it only says where
// the updates are checked.
(() => {
  "use strict";
  const $ = (id) => document.getElementById(id);
  const T = window.__TAURI__ && window.__TAURI__.core ? window.__TAURI__.core : null;
  if (!T || !$("aboutCheck")) return;
  const msg = (t) => { $("aboutMsg").textContent = t || ""; };
  const text = (e) => (typeof e === "string" ? e : (e && e.message) || "That didn’t work.");

  $("aboutBrowser").hidden = true;
  $("aboutUpd").hidden = false;
  T.invoke("app_version").then((v) => {
    $("aboutAppVer").textContent = v; $("aboutAppVer").hidden = false; $("aboutAppLabel").hidden = false;
  }).catch(() => { /* an older app: no version to show, the update button is also missing */ });

  const check = $("aboutCheck"), install = $("aboutInstall");
  check.addEventListener("click", async () => {
    check.disabled = true; $("aboutFound").hidden = true; msg("Looking for a newer version…");
    try {
      const found = await T.invoke("check_update");
      if (!found) { msg("You have the latest version."); return; }
      $("aboutNew").textContent = found.version;
      $("aboutNotes").textContent = (found.notes || "").trim() || "No notes for this version.";
      $("aboutFound").hidden = false;
      msg("Version " + found.version + " is available.");
    } catch (e) {
      msg("Couldn’t check just now: " + text(e));
    } finally { check.disabled = false; }
  });
  install.addEventListener("click", async () => {
    install.disabled = check.disabled = true; msg("Downloading and installing. The app restarts by itself when it is done…");
    try { await T.invoke("install_update"); } catch (e) { msg(text(e)); install.disabled = check.disabled = false; }
  });
})();
