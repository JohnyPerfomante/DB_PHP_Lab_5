"use strict";
document.querySelectorAll("form[data-confirm]").forEach((form) => {
  form.addEventListener("submit", (event) => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});
const type = document.querySelector("#type");
if (type) {
  const sync = () => {
    for (const [selector, active] of [
      ["[data-source]", type.value !== "receipt"],
      ["[data-destination]", type.value !== "issue"],
    ]) {
      const label = document.querySelector(selector);
      label.hidden = !active;
      label.querySelector("select").disabled = !active;
      label.querySelector("select").required = active;
    }
  };
  type.addEventListener("change", sync);
  sync();
}
