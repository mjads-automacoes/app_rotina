// Comum a todas as telas: tema, menu no celular, confirmacoes, busca de clientes e utilitarios (window.Rotina).
// Todo texto vindo do servidor entra no DOM por textContent (sem innerHTML).
(function () {
  "use strict";

  const raiz = document.documentElement;
  const csrf = document.body.dataset.csrf || "";

  // ---- tema
  document.addEventListener("click", (event) => {
    if (event.target.closest("[data-theme-toggle]")) {
      // tres temas, em roda: escuro -> neon (inspirado no GTA VI, 26/11/2026) -> claro -> escuro
      const novo = { escuro: "neon", neon: "claro", claro: "escuro" }[raiz.dataset.tema] || "escuro";
      raiz.dataset.tema = novo;
      try { window.localStorage.setItem("rotina_tema", novo); } catch (e) { /* sem armazenamento: so vale nesta visita */ }
    }
    if (event.target.closest("[data-menu-toggle]")) document.body.classList.toggle("menu-aberto");
    // confirmacao propria, no meio da tela (pedido do dono, 23/11/2026); depois do "sim" o clique segue normalmente
    const botao = event.target.closest("[data-confirmar]");
    if (botao) {
      if (botao.dataset.confirmado === "1") { delete botao.dataset.confirmado; return; }
      event.preventDefault();
      confirmar(botao.dataset.confirmar).then((sim) => { if (sim) { botao.dataset.confirmado = "1"; botao.click(); } });
    }
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
        avisarRotina();
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
    const barra = form.querySelector("[data-obs-barra]");
    if (barra) barra.hidden = false;
    campo.querySelector("textarea").focus();
  });

  // ---- notas da ficha: a coluna da direita nao passa do fim de "Dados do cliente"; o resto das notas rola por dentro
  // (barra de rolagem escondida). Pedido do dono, 24/11/2026.
  (function () {
    const esquerda = document.querySelector(".ficha-grade > .cartao");
    if (!esquerda || !document.querySelector("[data-notas-lista]")) return;
    const ajustar = () => {
      const lista = document.querySelector("[data-notas-lista]"); // o bloco de notas e trocado ao vivo: busca de novo
      const direita = lista && lista.closest(".coluna");
      if (!lista || !direita) return;
      lista.style.maxHeight = "";
      if (getComputedStyle(esquerda.parentElement).gridTemplateColumns.split(" ").length < 2) return; // celular: uma coluna so
      const sobra = direita.getBoundingClientRect().bottom - esquerda.getBoundingClientRect().bottom;
      if (sobra > 0) lista.style.maxHeight = Math.max(160, lista.scrollHeight - sobra) + "px";
    };
    ajustar();
    window.addEventListener("resize", ajustar);
    if (window.ResizeObserver) new ResizeObserver(ajustar).observe(esquerda);
    document.addEventListener("click", () => setTimeout(ajustar, 0));
    document.addEventListener("ficha-atualizada", ajustar);
  })();

  // ---- tarefas da ficha: clicar na tarefa abre/fecha os detalhes dela
  document.addEventListener("click", (event) => {
    const abrir = event.target.closest("[data-tarefa-abrir]");
    if (!abrir) return;
    const det = abrir.closest("li").querySelector("[data-tarefa-detalhes]");
    det.hidden = !det.hidden;
    abrir.setAttribute("aria-expanded", det.hidden ? "false" : "true");
  });

  // ---- notas da ficha: lapis abre a edicao no lugar do texto; cancelar volta (pedido do dono, 24/11/2026)
  document.addEventListener("click", (event) => {
    const editar = event.target.closest("[data-nota-editar]");
    const cancelar = event.target.closest("[data-nota-cancelar]");
    const nota = (editar || cancelar) && (editar || cancelar).closest("[data-nota]");
    if (!nota) return;
    const form = nota.querySelector("[data-nota-form]");
    nota.querySelector("[data-nota-texto]").hidden = !!editar;
    form.hidden = !editar;
    if (editar) { const t = form.querySelector("textarea"); t.focus(); t.setSelectionRange(t.value.length, t.value.length); }
  });

  // ---- barra das observacoes: # titulo, B negrito, I italico, U sublinhado (marcacao em texto, salva sozinha)
  document.addEventListener("mousedown", (event) => { if (event.target.closest("[data-formato]")) event.preventDefault(); });
  document.addEventListener("click", (event) => {
    const botao = event.target.closest("[data-formato]");
    if (!botao) return;
    const area = botao.closest("form").querySelector("textarea[name=observacoes]");
    const v = area.value;
    let ini = area.selectionStart, fim = area.selectionEnd;
    if (botao.dataset.formato === "titulo") {
      // liga/desliga "# " no comeco da linha onde esta o cursor
      const linha = v.lastIndexOf("\n", ini - 1) + 1;
      const tem = v.startsWith("# ", linha);
      area.value = v.slice(0, linha) + (tem ? v.slice(linha + 2) : "# " + v.slice(linha));
      ini = fim = Math.max(linha, ini + (tem ? -2 : 2));
      area.setSelectionRange(ini, tem ? Math.max(linha, fim) : fim);
    } else {
      const m = { negrito: "**", italico: "*", sublinhado: "++" }[botao.dataset.formato];
      const sel = v.slice(ini, fim);
      const marcado = v.slice(ini - m.length, ini) === m && v.slice(fim, fim + m.length) === m;
      if (marcado && !(m === "*" && v.slice(ini - 2, ini) === "**")) {
        area.value = v.slice(0, ini - m.length) + sel + v.slice(fim + m.length);
        area.setSelectionRange(ini - m.length, fim - m.length);
      } else {
        area.value = v.slice(0, ini) + m + sel + m + v.slice(fim);
        area.setSelectionRange(ini + m.length, fim + m.length);
      }
    }
    area.focus();
    area.dispatchEvent(new Event("input", { bubbles: true }));
  });

  // ---- cores vindas dos dados (CSP: sem style inline no HTML, so via script)
  document.querySelectorAll("[data-cor]").forEach((n) => { n.style.background = n.dataset.cor; });
  document.querySelectorAll("[data-agente-cor]").forEach((n) => { n.style.setProperty("--cor", n.dataset.agenteCor); }); // cor de cada personagem da aba Agentes

  // ---- menu de selecao (dias da rotina): atualiza o rotulo e fecha ao clicar fora
  document.addEventListener("change", (event) => {
    const menu = event.target.closest("[data-multi]");
    if (!menu) return;
    const marcados = [...menu.querySelectorAll("input[type=checkbox]:checked")].map((i) => i.dataset.curto);
    const dias = !menu.dataset.multiVazio; // o menu dos dias tem "Segunda a sexta"; os outros so listam o que foi marcado
    menu.querySelector("[data-multi-rotulo]").textContent = marcados.length === 0 ? (menu.dataset.multiVazio || "Selecione os dias") : dias && marcados.length === 5 ? "Segunda a sexta" : marcados.join(", ");
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

  // ---- CNPJ: formata enquanto digita (00.000.000/0000-00)
  document.addEventListener("input", (ev) => {
    const c = ev.target;
    if (!c.matches || !c.matches("input[data-cnpj]")) return;
    const n = c.value.replace(/\D/g, "").slice(0, 14);
    const partes = [n.slice(0, 2), n.slice(2, 5), n.slice(5, 8), n.slice(8, 12), n.slice(12, 14)];
    let v = partes[0];
    if (n.length > 2) v += "." + partes[1];
    if (n.length > 5) v += "." + partes[2];
    if (n.length > 8) v += "/" + partes[3];
    if (n.length > 12) v += "-" + partes[4];
    c.value = v;
  }, true);

  // ---- busca de clientes (pagina Clientes)
  const busca = document.getElementById("clientesBusca");
  if (busca) {
    busca.addEventListener("input", () => {
      const termo = busca.value.trim().toLowerCase();
      document.querySelectorAll("#clientesLista [data-nome]").forEach((c) => { c.hidden = termo !== "" && !c.dataset.nome.includes(termo); });
      document.dispatchEvent(new Event("clientes-filtrados"));
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

  // ---- calendario proprio (ficha: prazo da nova tarefa). Em portugues, sem dias que ja passaram (pedido do dono, 23/11/2026)
  const MESES = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"];
  const iso = (d) => d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
  const deIso = (s) => { const [a, m, d] = s.split("-").map(Number); return new Date(a, m - 1, d); };
  function iniciarCalendario(caixa) {
    const campo = caixa.querySelector("input[type=hidden]");
    const botao = caixa.querySelector("[data-cal-abrir]");
    const texto = caixa.querySelector("[data-cal-texto]");
    const min = caixa.dataset.min || iso(new Date());
    let mes = deIso(min); mes.setDate(1);
    let painel = null;
    const fechar = () => { if (painel) { painel.remove(); painel = null; botao.setAttribute("aria-expanded", "false"); } };
    const escolher = (valor) => {
      campo.value = valor;
      texto.textContent = valor ? valor.slice(8, 10) + "/" + valor.slice(5, 7) + "/" + valor.slice(0, 4) : "Prazo";
      caixa.classList.toggle("tem-data", !!valor);
      fechar();
      botao.focus();
    };
    const desenhar = () => {
      const primeiro = new Date(mes.getFullYear(), mes.getMonth(), 1);
      const inicio = new Date(primeiro); inicio.setDate(1 - primeiro.getDay());
      const minMes = deIso(min); minMes.setDate(1);
      const dias = [];
      for (let i = 0; i < 42; i++) {
        const d = new Date(inicio); d.setDate(inicio.getDate() + i);
        const v = iso(d);
        const fora = d.getMonth() !== mes.getMonth();
        if (fora && i >= 35) break;
        dias.push(el("button", { type: "button", class: "cal-dia" + (fora ? " is-fora" : "") + (v === min ? " is-hoje" : "") + (v === campo.value ? " is-escolhido" : ""), "data-dia": v, disabled: v < min, "aria-label": d.getDate() + " de " + MESES[d.getMonth()], text: String(d.getDate()) }));
      }
      const amanha = deIso(min); amanha.setDate(amanha.getDate() + 1);
      painel.replaceChildren(
        el("div", { class: "cal-topo" }, [
          el("button", { type: "button", class: "cal-nav", "data-cal-nav": "-1", "aria-label": "Mês anterior", disabled: mes <= minMes, text: "‹" }),
          el("strong", { text: MESES[mes.getMonth()][0].toUpperCase() + MESES[mes.getMonth()].slice(1) + " de " + mes.getFullYear() }),
          el("button", { type: "button", class: "cal-nav", "data-cal-nav": "1", "aria-label": "Próximo mês", text: "›" }),
        ]),
        el("div", { class: "cal-semana", "aria-hidden": "true" }, ["D", "S", "T", "Q", "Q", "S", "S"].map((l) => el("span", { text: l }))),
        el("div", { class: "cal-dias" }, dias),
        el("div", { class: "cal-rodape" }, [
          el("button", { type: "button", class: "cal-atalho", "data-dia": min, text: "Hoje" }),
          el("button", { type: "button", class: "cal-atalho", "data-dia": iso(amanha), text: "Amanhã" }),
          el("button", { type: "button", class: "cal-atalho cal-limpar", "data-dia": "", text: "Sem prazo" }),
        ]),
      );
    };
    botao.addEventListener("click", () => {
      if (painel) return fechar();
      if (campo.value) { mes = deIso(campo.value); mes.setDate(1); }
      painel = el("div", { class: "cal-painel", role: "dialog", "aria-label": "Escolher prazo" });
      painel.addEventListener("click", (ev) => {
        ev.stopPropagation();
        const nav = ev.target.closest("[data-cal-nav]");
        if (nav && !nav.disabled) { mes.setMonth(mes.getMonth() + Number(nav.dataset.calNav)); return desenhar(); }
        const dia = ev.target.closest("[data-dia]");
        if (dia && !dia.disabled) escolher(dia.dataset.dia);
      });
      desenhar();
      caixa.append(painel);
      botao.setAttribute("aria-expanded", "true");
    });
    document.addEventListener("click", (ev) => { if (painel && !caixa.contains(ev.target)) fechar(); });
    document.addEventListener("keydown", (ev) => { if (ev.key === "Escape" && painel) { fechar(); botao.focus(); } });
    // ao adicionar a tarefa, o formulario recarrega a pagina; nada a limpar aqui
  }
  document.querySelectorAll("[data-calendario]").forEach(iniciarCalendario);

  // Pop-up de confirmacao simples, sempre no meio da tela. Na ficha em janela (iframe), abre na tela principal.
  function confirmar(mensagem, acao) {
    if (document.body.classList.contains("embutido") && window.parent !== window) {
      try { if (window.parent.Rotina && window.parent.Rotina.confirmar) return window.parent.Rotina.confirmar(mensagem, acao); } catch (e) { /* outra origem */ }
    }
    const rotulo = acao || (/^(excluir|apagar|remover|desconectar)/i.test(mensagem) ? mensagem.split(" ")[0] : "Confirmar");
    const perigo = /^(excluir|apagar|remover|desconectar)/i.test(rotulo);
    return new Promise((resolver) => {
      const sim = el("button", { class: "btn " + (perigo ? "btn-perigo" : "btn-primario"), type: "button", "data-confirmar-ok": "", text: rotulo });
      const nao = el("button", { class: "btn btn-contorno", type: "button", "data-confirmar-nao": "", text: "Cancelar" });
      const caixa = el("div", { class: "confirmar-caixa", role: "alertdialog", "aria-modal": "true", "aria-label": mensagem }, [
        el("p", { class: "confirmar-texto", text: mensagem }),
        el("div", { class: "confirmar-botoes" }, [nao, sim]),
      ]);
      const fundo = el("div", { class: "confirmar-fundo" }, [caixa]);
      const fim = (valor) => { fundo.remove(); document.removeEventListener("keydown", tecla, true); resolver(valor); };
      const tecla = (ev) => {
        if (ev.key === "Escape") { ev.preventDefault(); ev.stopPropagation(); fim(false); }
        if (ev.key === "Enter") { ev.preventDefault(); ev.stopPropagation(); fim(true); }
      };
      sim.addEventListener("click", () => fim(true));
      nao.addEventListener("click", () => fim(false));
      fundo.addEventListener("click", (ev) => { if (ev.target === fundo) fim(false); });
      document.addEventListener("keydown", tecla, true);
      document.body.append(fundo);
      sim.focus();
    });
  }

  // ---- menus de selecao proprios (pedido do dono, 23/11/2026): a lista que abre tambem segue o visual do sistema.
  // O <select> original continua no formulario (escondido) e recebe o valor e o evento "change" de sempre.
  function selecao(select) {
    if (select.multiple || select.dataset.selecaoPronta) return;
    select.dataset.selecaoPronta = "1";
    const caixa = el("div", { class: "selecao" + (select.classList.contains("campo-curto") ? " campo-curto" : "") });
    const texto = el("span", { class: "selecao-texto" });
    const botao = el("button", { class: "selecao-botao", type: "button", "aria-haspopup": "listbox", "aria-expanded": "false" }, [texto]);
    if (select.getAttribute("aria-label")) botao.setAttribute("aria-label", select.getAttribute("aria-label"));
    if (select.id) botao.dataset.selecaoDe = select.id;
    select.parentNode.insertBefore(caixa, select);
    caixa.append(select, botao);
    select.classList.add("selecao-original");
    select.tabIndex = -1;
    // clicar no rotulo do campo so poe o foco no menu novo (nunca abre a lista antiga do navegador)
    const rotulo = select.closest("label");
    if (rotulo) rotulo.addEventListener("click", (ev) => { if (!caixa.contains(ev.target) && !ev.target.closest("a, button, input, textarea")) { ev.preventDefault(); botao.focus(); } });
    let lista = null;
    const atualizar = () => {
      const op = select.options[select.selectedIndex];
      texto.textContent = op ? op.textContent : "";
      botao.disabled = select.disabled;
    };
    const fechar = () => { if (lista) { lista.remove(); lista = null; botao.setAttribute("aria-expanded", "false"); } };
    const escolher = (i) => {
      fechar();
      if (select.selectedIndex !== i) { select.selectedIndex = i; select.dispatchEvent(new Event("input", { bubbles: true })); select.dispatchEvent(new Event("change", { bubbles: true })); }
      atualizar();
      botao.focus();
    };
    const marcar = (n) => {
      const itens = [...lista.querySelectorAll(".selecao-op:not(:disabled)")];
      if (!itens.length) return;
      const atual = itens.indexOf(document.activeElement);
      itens[(atual + n + itens.length) % itens.length].focus();
    };
    botao.addEventListener("click", (ev) => {
      ev.preventDefault();
      if (lista) return fechar();
      lista = el("div", { class: "selecao-lista", role: "listbox" }, [...select.options].map((op, i) =>
        el("button", { type: "button", role: "option", class: "selecao-op" + (i === select.selectedIndex ? " is-escolhida" : ""), "aria-selected": i === select.selectedIndex ? "true" : "false", disabled: op.disabled, "data-indice": i, text: op.textContent })));
      lista.addEventListener("click", (e) => { e.preventDefault(); const b = e.target.closest(".selecao-op"); if (b) escolher(Number(b.dataset.indice)); });
      lista.addEventListener("keydown", (e) => {
        if (e.key === "ArrowDown") { e.preventDefault(); marcar(1); }
        if (e.key === "ArrowUp") { e.preventDefault(); marcar(-1); }
        if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); fechar(); botao.focus(); }
        if (e.key === "Tab") fechar();
      });
      caixa.append(lista);
      botao.setAttribute("aria-expanded", "true");
      // perto do fim da tela, abre para cima
      if (lista.getBoundingClientRect().bottom > window.innerHeight - 8) lista.classList.add("para-cima");
      (lista.querySelector(".is-escolhida") || lista.querySelector(".selecao-op:not(:disabled)") || botao).focus();
    });
    botao.addEventListener("keydown", (e) => { if ((e.key === "ArrowDown" || e.key === "ArrowUp") && !lista) { e.preventDefault(); botao.click(); } });
    document.addEventListener("click", (e) => { if (lista && !caixa.contains(e.target)) fechar(); });
    select.addEventListener("change", atualizar);
    new MutationObserver(atualizar).observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ["disabled"] });
    atualizar();
  }
  document.querySelectorAll("select:not([multiple])").forEach(selecao);
  // menus criados depois por script (automacoes, Add + do checklist, filtros de tarefas) tambem ganham o visual proprio
  new MutationObserver((mudancas) => {
    for (const m of mudancas) for (const n of m.addedNodes) {
      if (n.nodeType !== 1) continue;
      if (n.matches("select:not([multiple])")) selecao(n);
      n.querySelectorAll && n.querySelectorAll("select:not([multiple])").forEach(selecao);
    }
  }).observe(document.body, { childList: true, subtree: true });

  // ---- emojis (pedido do dono, 24/11/2026, opcao A: os emojis do proprio aparelho).
  // Ao clicar num campo de texto aparece um 😊 no canto dele; ele abre a lista e o emoji entra onde estava o cursor.
  const EMOJIS = [
    ["😀", "Carinhas", "😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 🙃 😉 😌 😍 🥰 😘 😋 😛 😜 🤪 😎 🤩 🥳 😏 😒 😞 😔 😟 😕 🙁 😣 😖 😫 😩 🥺 😢 😭 😤 😠 😡 🤬 🤯 😳 😱 😨 😰 😥 😓 🤗 🤔 🤭 🤫 🤥 😶 😐 😑 😬 🙄 😯 😦 😮 😲 🥱 😴 🤤 😪 😵 🤐 🥴 🤢 🤮 🤧 😷 🤒 🤕 🤑 🤠 😈 👻 💀 🤖 💩"],
    ["👍", "Gestos", "👍 👎 👌 🤌 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 👏 🙌 👐 🤲 🤝 🙏 ✍️ 💪 🫶 👀 🧠 🗣️ 👤 👥 🙋 🙆 🙅 🤷 🤦 🧑‍💻 👨‍💼 👩‍💼"],
    ["❤️", "Corações", "❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 ✨ 🔥 💯 💥 💫 ⭐ 🌟"],
    ["💼", "Trabalho", "💼 📈 📉 📊 💰 💵 💸 💳 🧾 🏷️ 🛒 🛍️ 📦 📣 📢 📱 💻 🖥️ ⌨️ 🖱️ 📷 🎥 🎬 📞 ☎️ 📧 📩 📨 💬 🗨️ 📝 ✏️ 🖊️ 📌 📍 📎 🗂️ 📁 📅 📆 🗓️ ⏰ ⏳ ⌛ 🔍 🔑 🔒 🔓 🔔 🔕 💡 🛠️ ⚙️ 🧩 🚀 🎯 🏆 🥇 🎉 🎊 🎁"],
    ["✅", "Símbolos", "✅ ☑️ ✔️ ❌ ❎ ⚠️ 🚫 ⛔ ❗ ❓ ‼️ ⁉️ 🔴 🟠 🟡 🟢 🔵 🟣 ⚫ ⚪ 🟥 🟧 🟨 🟩 🟦 🟪 ⬆️ ⬇️ ➡️ ⬅️ 🔁 🔄 ➕ ➖ ✖️ ➗ 🆕 🆗 🆙 🔝 🔜 💲 #️⃣ 1️⃣ 2️⃣ 3️⃣ 4️⃣ 5️⃣"],
    ["🍕", "Outros", "☀️ 🌤️ ⛅ 🌧️ ⛈️ ❄️ 🌈 🌙 🌎 🏠 🏢 🏪 🏥 🏫 🚗 🚚 ✈️ 🍕 🍔 🍟 🍰 ☕ 🍺 🥂 🍎 🍀 🌱 🌻 🐶 🐱 ⚽ 🏀 🎮 🎵 🎧 📚 🎓 🙏🏻"],
  ];
  // fora do login e da primeira configuracao (telas sem menu), pedido do dono
  const campoComEmoji = (n) => !document.body.classList.contains("sem-menu") && n && !n.hasAttribute("data-sem-emoji") && ((n.tagName === "TEXTAREA") || (n.tagName === "INPUT" && (!n.getAttribute("type") || n.type === "text"))) && !n.readOnly && !n.disabled;
  let emojiCampo = null;
  let emojiBotao = null;
  let emojiPainel = null;
  const emojiFecharPainel = () => { if (emojiPainel) { emojiPainel.remove(); emojiPainel = null; } };
  const emojiPosicionar = () => {
    if (!emojiBotao || !emojiCampo) return;
    if (!emojiCampo.isConnected) { emojiEsconder(); return; }
    const r = emojiCampo.getBoundingClientRect();
    emojiBotao.style.top = (emojiCampo.tagName === "TEXTAREA" ? r.top + 6 : r.top + (r.height - 28) / 2) + "px";
    emojiBotao.style.left = (r.right - 34) + "px";
    if (emojiPainel) {
      const alto = emojiPainel.offsetHeight || 300;
      const largura = emojiPainel.offsetWidth || 320;
      const cima = r.bottom + alto + 8 > window.innerHeight && r.top - alto - 8 > 0;
      emojiPainel.style.top = (cima ? r.top - alto - 6 : Math.min(r.bottom + 6, window.innerHeight - alto - 8)) + "px";
      emojiPainel.style.left = Math.max(8, Math.min(r.right - largura, window.innerWidth - largura - 8)) + "px";
    }
  };
  function emojiEsconder() { emojiFecharPainel(); if (emojiBotao) emojiBotao.hidden = true; if (emojiCampo) emojiCampo.classList.remove("com-emoji"); emojiCampo = null; }
  const emojiInserir = (emoji) => {
    const c = emojiCampo;
    if (!c) return;
    const ini = c.selectionStart == null ? c.value.length : c.selectionStart;
    const fim = c.selectionEnd == null ? c.value.length : c.selectionEnd;
    const max = parseInt(c.getAttribute("maxlength") || "0", 10);
    if (max && c.value.length - (fim - ini) + emoji.length > max) { toast("O campo chegou ao limite de caracteres.", true); return; }
    c.setRangeText(emoji, ini, fim, "end");
    c.focus();
    c.dispatchEvent(new Event("input", { bubbles: true }));
  };
  const emojiAbrirPainel = () => {
    if (emojiPainel) { emojiFecharPainel(); return; }
    // todos os emojis numa lista so, rolando so na vertical; as abas pulam para cada grupo (pedido do dono)
    const grade = el("div", { class: "emoji-grade" }, EMOJIS.map(([, nome, lista], i) => el("section", { class: "emoji-grupo", "data-grupo": i }, [
      el("p", { class: "emoji-grupo-nome", text: nome }),
      el("div", { class: "emoji-itens" }, lista.split(" ").map((e) => el("button", { type: "button", class: "emoji-item", "data-emoji": e, title: e, text: e }))),
    ])));
    const abas = el("div", { class: "emoji-abas", role: "tablist" });
    const marcarAba = (i) => [...abas.children].forEach((b, j) => b.classList.toggle("is-ativa", i === j));
    const mostrarAba = (i) => { const g = grade.querySelector('[data-grupo="' + i + '"]'); grade.scrollTop = g.offsetTop; marcarAba(i); };
    grade.addEventListener("scroll", () => {
      let atual = 0;
      grade.querySelectorAll(".emoji-grupo").forEach((g, i) => { if (g.offsetTop <= grade.scrollTop + 8) atual = i; });
      marcarAba(atual);
    });
    EMOJIS.forEach(([icone, nome], i) => abas.append(el("button", { type: "button", class: "emoji-aba", title: nome, "aria-label": nome, "data-aba": i, text: icone })));
    emojiPainel = el("div", { class: "emoji-painel", role: "dialog", "aria-label": "Emojis" }, [abas, grade]);
    emojiPainel.addEventListener("mousedown", (ev) => ev.preventDefault()); // o campo continua com o cursor
    emojiPainel.addEventListener("click", (ev) => {
      const aba = ev.target.closest("[data-aba]");
      if (aba) return mostrarAba(Number(aba.dataset.aba));
      const item = ev.target.closest("[data-emoji]");
      if (item) emojiInserir(item.dataset.emoji);
    });
    document.body.append(emojiPainel);
    marcarAba(0);
    emojiPosicionar();
  };
  document.addEventListener("focusin", (ev) => {
    const c = ev.target;
    if (emojiPainel && emojiPainel.contains(c)) return;
    if (!campoComEmoji(c)) { if (c !== emojiBotao) emojiEsconder(); return; }
    if (emojiCampo && emojiCampo !== c) { emojiFecharPainel(); emojiCampo.classList.remove("com-emoji"); }
    emojiCampo = c;
    c.classList.add("com-emoji");
    if (!emojiBotao) {
      emojiBotao = el("button", { type: "button", class: "emoji-botao", title: "Emojis", "aria-label": "Inserir emoji", text: "😊" });
      emojiBotao.addEventListener("mousedown", (e) => e.preventDefault());
      emojiBotao.addEventListener("click", emojiAbrirPainel);
      document.body.append(emojiBotao);
    }
    emojiBotao.hidden = false;
    emojiPosicionar();
  });
  document.addEventListener("mousedown", (ev) => {
    if (!emojiCampo) return;
    if (ev.target === emojiCampo || ev.target === emojiBotao || (emojiPainel && emojiPainel.contains(ev.target))) return;
    if (!campoComEmoji(ev.target)) emojiEsconder();
  });
  document.addEventListener("keydown", (ev) => { if (ev.key === "Escape" && emojiPainel) { ev.stopPropagation(); emojiFecharPainel(); } }, true);
  window.addEventListener("scroll", emojiPosicionar, true);
  window.addEventListener("resize", emojiPosicionar);

  // ---- ficha ao vivo (pedido do dono, 24/11/2026): adicionar/excluir tarefa e adicionar/editar/excluir nota
  // atualizam so o bloco, sem recarregar a pagina. O formulario vai pelo fetch; da resposta (a ficha nova)
  // troca-se cada bloco [data-ao-vivo] e o aviso vira notificacao.
  // ficha aberta em janela sobre a Rotina: a Rotina atras se atualiza na hora
  function avisarRotina() {
    try { if (window.parent !== window && window.parent.Rotina && window.parent.Rotina.recarregarRotina) window.parent.Rotina.recarregarRotina(); } catch (e) { /* outra origem */ }
  }
  window.Rotina = window.Rotina || {};
  function prepararTrecho(raizTrecho) {
    raizTrecho.querySelectorAll("[data-cor]").forEach((n) => { n.style.background = n.dataset.cor; });
    raizTrecho.querySelectorAll("[data-calendario]").forEach(iniciarCalendario);
  }
  document.addEventListener("submit", async (ev) => {
    const form = ev.target;
    if (!form.closest || !form.closest("[data-ao-vivo]") || ev.defaultPrevented) return;
    ev.preventDefault();
    const botoes = form.querySelectorAll("button");
    botoes.forEach((b) => { b.disabled = true; });
    try {
      const resp = await fetch(form.action, { method: "POST", body: new URLSearchParams(new FormData(form)), credentials: "same-origin", headers: { Accept: "text/html" } });
      if (resp.url.includes("/login")) { window.location.href = "/login"; return; }
      const novo = new DOMParser().parseFromString(await resp.text(), "text/html");
      document.querySelectorAll("[data-ao-vivo]").forEach((bloco) => {
        const troca = novo.querySelector('[data-ao-vivo="' + bloco.dataset.aoVivo + '"]');
        if (!troca) return;
        const importado = document.importNode(troca, true);
        bloco.replaceWith(importado);
        prepararTrecho(importado);
      });
      novo.querySelectorAll("[data-notificacao]").forEach((n) => toast(n.textContent.trim(), n.dataset.notificacao === "erro"));
      document.dispatchEvent(new Event("ficha-atualizada"));
      avisarRotina();
    } catch (e) {
      toast("Não foi possível salvar: " + e.message, true);
      botoes.forEach((b) => { b.disabled = false; });
    }
  });

  window.Rotina = { el, toast, chamar, dataBR, iniciais, confirmar, selecao };
})();
