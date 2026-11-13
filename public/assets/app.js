// Comum a todas as telas: tema, menu no celular, confirmacoes, busca de clientes e utilitarios (window.Rotina).
// Todo texto vindo do servidor entra no DOM por textContent (sem innerHTML).
(function () {
  "use strict";

  const raiz = document.documentElement;
  const csrf = document.body.dataset.csrf || "";

  // ---- tema
  document.addEventListener("click", (event) => {
    if (event.target.closest("[data-theme-toggle]")) {
      const novo = raiz.dataset.tema === "claro" ? "escuro" : "claro";
      raiz.dataset.tema = novo;
      try { window.localStorage.setItem("rotina_tema", novo); } catch (e) { /* sem armazenamento: so vale nesta visita */ }
    }
    if (event.target.closest("[data-menu-toggle]")) document.body.classList.toggle("menu-aberto");
    const botao = event.target.closest("[data-confirmar]");
    if (botao && !window.confirm(botao.dataset.confirmar)) event.preventDefault();
  });

  // ---- cores vindas dos dados (CSP: sem style inline no HTML, so via script)
  document.querySelectorAll("[data-cor]").forEach((n) => { n.style.background = n.dataset.cor; });

  // ---- menu de selecao (dias da rotina): atualiza o rotulo e fecha ao clicar fora
  document.addEventListener("change", (event) => {
    const menu = event.target.closest("[data-multi]");
    if (!menu) return;
    const marcados = [...menu.querySelectorAll("input[type=checkbox]:checked")].map((i) => i.dataset.curto);
    menu.querySelector("[data-multi-rotulo]").textContent = marcados.length === 0 ? "Selecione os dias" : marcados.length === 5 ? "Segunda a sexta" : marcados.join(", ");
  });
  document.addEventListener("click", (event) => {
    document.querySelectorAll("[data-multi][open]").forEach((m) => { if (!m.contains(event.target)) m.open = false; });
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") document.querySelectorAll("[data-multi][open]").forEach((m) => { m.open = false; });
  });

  // ---- abas da tela Rotina: "Rotina do dia" e "Tarefas" (o endereco /#tarefas abre direto nas tarefas)
  (function () {
    const painelRotina = document.getElementById("painelRotina");
    const painelTarefas = document.getElementById("tarefas");
    if (!painelRotina || !painelTarefas) return;
    const mostrar = (aba) => {
      painelRotina.hidden = aba === "tarefas";
      painelTarefas.hidden = aba !== "tarefas";
      try { history.replaceState(null, "", aba === "tarefas" ? "#tarefas" : location.pathname + location.search); } catch (e) { /* sem historico */ }
    };
    document.querySelectorAll("[data-aba]").forEach((b) => b.addEventListener("click", () => mostrar(b.dataset.aba)));
    window.addEventListener("hashchange", () => mostrar(location.hash === "#tarefas" ? "tarefas" : "rotina"));
    if (location.hash === "#tarefas") mostrar("tarefas");
  })();

  // ---- busca de clientes (pagina Clientes)
  const busca = document.getElementById("clientesBusca");
  if (busca) {
    busca.addEventListener("input", () => {
      const termo = busca.value.trim().toLowerCase();
      document.querySelectorAll("#clientesLista [data-nome]").forEach((c) => { c.hidden = termo !== "" && !c.dataset.nome.includes(termo); });
    });
  }

  // ---- utilitarios
  function el(tag, attrs, filhos) {
    const n = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
      if (v === false || v == null) return;
      if (k === "text") n.textContent = v;
      else if (k === "class") n.className = v;
      else if (k === "cor") n.style.background = v;
      else if (k === "value") n.value = v;
      else n.setAttribute(k, v === true ? "" : v);
    });
    (Array.isArray(filhos) ? filhos : filhos ? [filhos] : []).forEach((f) => { if (f != null && f !== false) n.append(f); });
    return n;
  }

  function toast(msg, erro) {
    const t = document.getElementById("toast");
    if (!t) return;
    t.textContent = msg;
    t.classList.toggle("is-erro", !!erro);
    t.hidden = false;
    clearTimeout(toast.t);
    toast.t = setTimeout(() => { t.hidden = true; }, 3500);
  }

  // JSON em ambos os sentidos; devolve o corpo ou lanca Error com a mensagem do servidor
  async function chamar(metodo, url, corpo) {
    const opcoes = { method: metodo, headers: { Accept: "application/json", "X-CSRF-Token": csrf }, credentials: "same-origin" };
    if (corpo !== undefined) { opcoes.headers["Content-Type"] = "application/json"; opcoes.body = JSON.stringify(corpo); }
    const resposta = await fetch(url, opcoes);
    if (resposta.status === 401) { window.location.href = "/login"; throw new Error("Sessão expirada."); }
    const json = await resposta.json().catch(() => null);
    if (!json || json.ok !== true) throw new Error((json && json.message) || "Não foi possível concluir.");
    return json;
  }

  const dataBR = (iso) => (iso ? iso.slice(8, 10) + "/" + iso.slice(5, 7) : "");
  const iniciais = (nome) => (nome || "?").trim().slice(0, 2).toUpperCase();

  window.Rotina = { el, toast, chamar, dataBR, iniciais };
})();
