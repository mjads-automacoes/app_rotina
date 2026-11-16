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

  // ---- ficha do cliente: salva sozinha a cada mudanca (sem botao Salvar)
  document.querySelectorAll("form[data-autosalvar]").forEach((form) => {
    const status = document.querySelector("[data-salvo-status]");
    const mostrar = (texto, classe) => { if (status) { status.textContent = texto; status.className = "salvo-status " + classe; } };
    let espera = null;
    let salvando = false;
    let deNovo = false;
    let sujo = false;

    // campos do formulario -> objeto (dias[] e plataformas[] viram listas)
    const dados = () => {
      const d = {};
      new FormData(form).forEach((valor, nome) => {
        if (nome === "csrf") return;
        if (nome.endsWith("[]")) (d[nome.slice(0, -2)] = d[nome.slice(0, -2)] || []).push(valor);
        else d[nome] = valor;
      });
      return d;
    };

    const salvar = async () => {
      clearTimeout(espera);
      if (!sujo) return;
      if (salvando) { deNovo = true; return; }
      salvando = true;
      sujo = false;
      mostrar("Salvando…", "is-salvando");
      try {
        const r = await chamar("POST", form.dataset.autosalvar, dados());
        mostrar("✓ Salvo", "is-salvo");
        const nome = r.cliente && r.cliente.nome;
        if (nome) {
          document.querySelectorAll("[data-cliente-nome]").forEach((n) => { n.textContent = nome; });
          try { const t = window.parent !== window && window.parent.document.querySelector(".janela-topo [data-janela-nome]"); if (t) t.textContent = nome; } catch (e) { /* outra origem */ }
        }
      } catch (e) {
        sujo = true;
        mostrar("Não salvo", "is-erro");
        toast("Não foi possível salvar: " + e.message, true);
      } finally {
        salvando = false;
        if (deNovo) { deNovo = false; salvar(); }
      }
    };

    // digitando: espera uma pausa curta; escolha (menu, caixa de marcar) ou saiu do campo: salva na hora
    form.addEventListener("input", (ev) => {
      sujo = true;
      mostrar("Editando…", "is-salvando");
      clearTimeout(espera);
      const imediato = ev.target.matches("select, input[type=checkbox], input[type=radio]");
      espera = setTimeout(salvar, imediato ? 0 : 700);
    });
    form.addEventListener("change", () => { if (sujo) salvar(); });
    form.addEventListener("submit", (ev) => { ev.preventDefault(); sujo = true; salvar(); });
    // fechando a janela ou saindo da pagina com algo pendente: envia mesmo assim
    window.addEventListener("pagehide", () => {
      if (!sujo) return;
      fetch(form.dataset.autosalvar, { method: "POST", keepalive: true, credentials: "same-origin", headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify(dados()) });
    });
  });

  // ---- texto longo (observacoes do ClickUp): recolhido, com Expandir / Recolher
  document.querySelectorAll("[data-recolher]").forEach((bloco) => {
    if (bloco.scrollHeight <= 260) return;
    bloco.classList.add("is-recolhido");
    const botao = el("button", { class: "btn-expandir", type: "button", text: "Expandir" });
    botao.addEventListener("click", () => {
      const recolhido = bloco.classList.toggle("is-recolhido");
      botao.textContent = recolhido ? "Expandir" : "Recolher";
    });
    bloco.after(botao);
  });

  // ---- observacoes: mostra o texto formatado; "Editar" troca pelo campo de texto
  document.addEventListener("click", (event) => {
    const editar = event.target.closest("[data-obs-editar]");
    if (!editar) return;
    const form = editar.closest("form");
    form.querySelector("[data-obs-ver]").hidden = true;
    const campo = form.querySelector("[data-obs-campo]");
    campo.hidden = false;
    campo.querySelector("textarea").focus();
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

  // Notificacoes: todas no canto superior direito, somem em 5 segundos (ou com um clique).
  // Na ficha aberta em janela (iframe), aparecem na tela principal.
  function toast(msg, erro) {
    if (!msg) return;
    try {
      if (document.body.classList.contains("embutido") && window.parent !== window && window.parent.Rotina) {
        window.parent.Rotina.toast(msg, erro);
        return;
      }
    } catch (e) { /* janela de outra origem: mostra aqui mesmo */ }
    let caixa = document.getElementById("notificacoes");
    if (!caixa) {
      caixa = el("div", { id: "notificacoes", class: "notificacoes", "aria-live": "polite" });
      document.body.appendChild(caixa);
    }
    const n = el("div", { class: "notificacao" + (erro ? " is-erro" : ""), role: erro ? "alert" : "status", text: msg });
    const fechar = () => {
      if (n.classList.contains("is-saindo")) return;
      n.classList.add("is-saindo");
      setTimeout(() => n.remove(), 250);
    };
    n.addEventListener("click", fechar);
    caixa.appendChild(n);
    setTimeout(fechar, 5000);
  }

  // avisos vindos do servidor (salvou, excluiu, erro de login...) viram notificacao
  document.querySelectorAll("[data-notificacao]").forEach((aviso) => {
    toast(aviso.textContent.trim(), aviso.dataset.notificacao === "erro");
    aviso.remove();
  });

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
