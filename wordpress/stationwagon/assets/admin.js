// The setup wizard: show only the fields the chosen options need, and the cPanel login only when
// a choice needs it. Without this script every field shows, and the form still works.
(() => {
  const form = document.getElementById("sw-form");
  if (!form) return;
  const cpanel = document.getElementById("sw-cpanel");
  const last = form.querySelector(".sw-n-last");

  const update = () => {
    for (const el of form.querySelectorAll("[data-for]")) {
      const [name, value] = el.dataset.for.split("=");
      const on = form.querySelector(`input[name="${name}"]:checked`)?.value === value;
      el.hidden = !on;
      for (const input of el.querySelectorAll("input")) input.disabled = !on;
    }
    const needs = !!form.querySelector("input[data-cpanel]:checked");
    cpanel.hidden = !needs;
    for (const input of cpanel.querySelectorAll("input")) input.required = needs;
    if (last) last.textContent = needs ? "5" : "4";
    // the example address follows the typed name
    for (const choice of form.querySelectorAll(".sw-choice")) {
      const input = choice.querySelector("input");
      const field = form.querySelector(`#sw-${input.value}`);
      const b = choice.querySelector("small code b");
      if (field && b) b.textContent = field.value.trim() || "radio";
    }
  };
  form.addEventListener("change", update);
  form.addEventListener("input", update);
  update();

  form.addEventListener("submit", () => {
    const button = form.querySelector('button[type="submit"]');
    // let the request go first, then stop a second click
    setTimeout(() => { button.disabled = true; }, 0);
    form.querySelector(".sw-wait").hidden = false;
  });
})();
