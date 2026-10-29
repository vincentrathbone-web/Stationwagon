// The installer: show only the fields the chosen options need, and the cPanel login only when a
// choice needs it. Without this script every field shows, and the form still works.
(() => {
  const form = document.getElementById("install-form");
  if (!form) return;
  const cpanel = document.getElementById("cpanel-login");
  const last = form.querySelector(".n-last");
  const update = () => {
    for (const el of form.querySelectorAll("[data-for]")) {
      const [name, value] = el.dataset.for.split("=");
      const on = form.querySelector(`input[name="${name}"]:checked`)?.value === value;
      el.hidden = !on;
      for (const input of el.querySelectorAll("input")) input.disabled = !on;
    }
    const needs = !!form.querySelector("input[data-cpanel]:checked");
    cpanel.hidden = !needs;
    if (last) last.textContent = needs ? "4" : "3";
  };
  form.addEventListener("change", update);
  update();
  form.addEventListener("submit", (e) => {
    if (e.submitter?.value !== "install") return;
    form.querySelector(".wait").hidden = false;
    setTimeout(() => { e.submitter.disabled = true; }, 0);
  });
})();
