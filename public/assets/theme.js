// Aplica o tema antes de pintar a pagina (sem piscar). Escolha salva no navegador; sem escolha, segue o sistema.
(function () {
  "use strict";
  var tema = null;
  try { tema = window.localStorage.getItem("rotina_tema"); } catch (e) { tema = null; }
  if (tema !== "claro" && tema !== "escuro") {
    tema = window.matchMedia && window.matchMedia("(prefers-color-scheme: light)").matches ? "claro" : "escuro";
  }
  document.documentElement.dataset.tema = tema;
})();
