// The sign-in page in Stationwagon Studio for Android: Android's Credential Manager offers the saved
// sign-in (Google Password Manager) and fills the form in; after signing in by typing, the app
// offers to save it. Browsers and the Windows app have their own password managers: nothing here.
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
