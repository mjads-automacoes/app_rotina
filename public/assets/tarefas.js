// Tarefas estilo ClickUp: vista Lista e Quadro (arrastar e soltar), filtros e gaveta de detalhes.
(function () {
  "use strict";

  const raiz = document.getElementById("tarefas");
  if (!raiz) return;
  const { el, toast, chamar, dataBR, iniciais } = window.Rotina;
  const $ = (id) => document.getElementById(id);
  const ui = { conteudo: $("tarefasConteudo"), gaveta: $("gaveta"), fundo: $("gavetaFundo"), busca: $("fBusca"), lista: $("fLista"), resp: $("fResponsavel"), prio: $("fPrioridade"), cliente: $("fCliente"), abertas: $("fAbertas") };
  const TURNOS = { "": "Sem turno", manha: "08h · Manhã", tarde: "13h · Tarde", fechamento: "17h · Fechamento" };
  const state = { meta: null, tarefas: [], vista: "lista", aberta: null, arrastando: null };

  const listaPor = (id) => state.meta.listas.find((l) => l.id === id);
  const hoje = () => state.meta.hoje;
  const atrasada = (t) => t.prazo && t.prazo < hoje() && t.status_tipo !== "fechado";

  function selecionar(sel, itens, valor, vazio) {
    sel.replaceChildren(...(vazio ? [el("option", { value: "", text: vazio })] : []), ...itens.map(([v, texto]) => el("option", { value: String(v), text: texto })));
    if (valor !== undefined && valor !== null) sel.value = String(valor);
  }

  // ---------- carga ----------
  async function iniciar() {
    state.meta = await chamar("GET", "/api/meta");
    selecionar(ui.lista, state.meta.listas.map((l) => [l.id, l.nome]), "", "Todas as listas");
    selecionar(ui.resp, state.meta.usuarios.map((u) => [u.id, u.nome]), "", "Responsável");
    selecionar(ui.prio, state.meta.prioridades.map((p) => [p.id, p.nome]), "", "Prioridade");
    selecionar(ui.cliente, state.meta.clientes.map((c) => [c.id, c.nome]), "", "Cliente");
    await recarregar();
  }

  function filtros() {
    const f = {};
    if (ui.busca.value.trim()) f.busca = ui.busca.value.trim();
    if (ui.lista.value) f.lista_id = ui.lista.value;
    if (ui.resp.value) f.responsavel_id = ui.resp.value;
    if (ui.prio.value) f.prioridade = ui.prio.value;
    if (ui.cliente.value) f.cliente_id = ui.cliente.value;
    if (ui.abertas.checked) f.abertas = "1";
    return f;
  }

  async function recarregar() {
    try {
      const q = new URLSearchParams(filtros()).toString();
      state.tarefas = (await chamar("GET", "/api/tarefas" + (q ? "?" + q : ""))).tarefas;
      desenhar();
    } catch (e) { toast(e.message, true); }
  }

  // ---------- pecas ----------
  function prioridade(t) { return el("span", { class: "prio prio-" + t.prioridade, text: t.prioridade_nome }); }
  function prazo(t) { return t.prazo ? el("span", { class: "prazo" + (atrasada(t) ? " atrasado" : ""), text: (atrasada(t) ? "Atrasada · " : "") + dataBR(t.prazo) }) : null; }
  function responsavel(t) { return t.responsavel_nome ? el("span", { class: "avatar", title: t.responsavel_nome, text: iniciais(t.responsavel_nome) }) : el("span", { class: "avatar", title: "Sem responsável", text: "–" }); }
  function etiquetas(t) { return (t.etiquetas || []).map((e) => el("span", { class: "etiqueta", cor: e.cor, text: e.nome })); }

  async function alternarFeita(t, evento) {
    evento.stopPropagation();
    const lista = listaPor(t.lista_id);
    const alvo = t.status_tipo === "fechado" ? lista.status.find((s) => s.tipo === "aberto") : lista.status.find((s) => s.tipo === "fechado");
    await mudarStatus(t.id, alvo.id);
  }

  async function mudarStatus(id, statusId) {
    try { await chamar("POST", "/api/tarefas/" + id + "/status", { status_id: statusId }); await recarregar(); if (state.aberta === id) abrir(id); }
    catch (e) { toast(e.message, true); await recarregar(); }
  }

  function linha(t) {
    const feita = t.status_tipo === "fechado";
    return el("div", { class: "tarefa-linha" + (feita ? " is-feita" : ""), role: "button", tabindex: "0", "data-id": t.id }, [
      el("button", { class: "ponto-status", type: "button", title: feita ? "Reabrir" : "Concluir", "aria-label": feita ? "Reabrir" : "Concluir", "data-concluir": t.id }, [el("span", { class: "rotina-check", text: feita ? "✓" : "" })]),
      el("div", { class: "grow" }, [el("div", { class: "tarefa-titulo", text: t.titulo }),
        el("div", { class: "tarefa-meta" }, [t.cliente_nome ? el("span", { text: t.cliente_nome }) : null, t.itens_total ? el("span", { text: "☑ " + t.itens_feitos + "/" + t.itens_total }) : null, ...etiquetas(t)])]),
      prioridade(t), prazo(t) || el("span"), responsavel(t),
    ]);
  }

  function grupo(titulo, cor, tarefas) {
    return [el("div", { class: "tarefa-grupo-topo" }, [el("i", { class: "ponto", cor: cor }), el("span", { text: titulo }), el("span", { class: "contagem", text: String(tarefas.length) })]),
      ...(tarefas.length ? tarefas.map(linha) : [el("p", { class: "muted", text: "Nada aqui." })])];
  }

  function vistaLista() {
    const l = ui.lista.value ? listaPor(parseInt(ui.lista.value, 10)) : null;
    const rapido = el("form", { class: "rapido", id: "rapido" }, [
      el("input", { type: "text", id: "rapidoTitulo", placeholder: "Adicionar tarefa e apertar Enter…", maxlength: "200", autocomplete: "off" }),
      el("select", { id: "rapidoLista", "aria-label": "Lista" }, state.meta.listas.map((x) => el("option", { value: String(x.id), text: x.nome }))),
      el("button", { class: "btn btn-primario", type: "submit" }, [el("span", { text: "Adicionar" })]),
    ]);
    const rl = rapido.querySelector("#rapidoLista");
    rl.value = String(l ? l.id : (state.meta.listas.find((x) => x.id !== 1) || state.meta.listas[0]).id);
    const partes = [rapido];

    if (l) l.status.forEach((s) => partes.push(...grupo(s.nome, s.cor, state.tarefas.filter((t) => t.status_id === s.id))));
    else state.meta.listas.forEach((x) => { const ts = state.tarefas.filter((t) => t.lista_id === x.id); if (ts.length) partes.push(...grupo(x.nome, x.cor, ts)); });
    if (!state.tarefas.length) partes.push(el("p", { class: "muted", text: "Nenhuma tarefa com esses filtros." }));
    ui.conteudo.replaceChildren(el("div", { class: "lista-tarefas" }, partes));
  }

  function vistaQuadro() {
    const l = ui.lista.value ? listaPor(parseInt(ui.lista.value, 10)) : (state.meta.listas.find((x) => x.id !== 1) || state.meta.listas[0]);
    if (!ui.lista.value) { ui.lista.value = String(l.id); recarregar(); return; }
    ui.conteudo.replaceChildren(el("div", { class: "quadro" }, l.status.map((s) => {
      const ts = state.tarefas.filter((t) => t.status_id === s.id);
      return el("div", { class: "coluna-quadro", "data-status": s.id }, [
        el("div", { class: "coluna-quadro-topo" }, [el("i", { class: "ponto", cor: s.cor }), el("span", { text: s.nome }), el("span", { class: "contagem", text: String(ts.length) })]),
        ...ts.map((t) => el("div", { class: "card-tarefa", draggable: "true", "data-id": t.id, role: "button", tabindex: "0" }, [
          el("div", { class: "tarefa-titulo", text: t.titulo }),
          t.cliente_nome ? el("span", { class: "muted", text: t.cliente_nome }) : null,
          el("div", { class: "tarefa-meta" }, [prioridade(t), prazo(t), ...etiquetas(t)]),
          el("div", { class: "tarefa-meta" }, [responsavel(t), t.itens_total ? el("span", { text: "☑ " + t.itens_feitos + "/" + t.itens_total }) : null]),
        ])),
      ]);
    })));
  }

  function desenhar() { return state.vista === "quadro" ? vistaQuadro() : vistaLista(); }

  // ---------- gaveta ----------
  function fechar() { ui.gaveta.hidden = true; ui.fundo.hidden = true; state.aberta = null; }

  function campo(rotulo, controle) { return el("label", {}, [el("span", { text: rotulo }), controle]); }

  async function abrir(id) {
    try {
      const t = (await chamar("GET", "/api/tarefas/" + id)).tarefa;
      state.aberta = id;
      renderGaveta(t);
    } catch (e) { toast(e.message, true); }
  }

  async function salvar(id, dados, recarregarGaveta) {
    try { await chamar("POST", "/api/tarefas/" + id, dados); await recarregar(); if (recarregarGaveta) await abrir(id); }
    catch (e) { toast(e.message, true); await abrir(id); }
  }

  function renderGaveta(t) {
    const lista = listaPor(t.lista_id);
    const selecao = (valores, atual, vazio) => { const s = el("select"); selecionar(s, valores, atual === null ? "" : atual, vazio); return s; };
    const titulo = el("input", { class: "titulo-input", type: "text", value: t.titulo, maxlength: "200", "aria-label": "Título" });
    titulo.addEventListener("change", () => salvar(t.id, { titulo: titulo.value }));
    const status = selecao(lista.status.map((s) => [s.id, s.nome]), t.status_id);
    status.addEventListener("change", () => mudarStatus(t.id, parseInt(status.value, 10)));
    const resp = selecao(state.meta.usuarios.map((u) => [u.id, u.nome]), t.responsavel_id, "Sem responsável");
    resp.addEventListener("change", () => salvar(t.id, { responsavel_id: resp.value }, true));
    const prio = selecao(state.meta.prioridades.map((p) => [p.id, p.nome]), t.prioridade);
    prio.addEventListener("change", () => salvar(t.id, { prioridade: prio.value }, true));
    const data = el("input", { type: "date", value: t.prazo || "" });
    data.addEventListener("change", () => salvar(t.id, { prazo: data.value }, true));
    const cliente = selecao(state.meta.clientes.map((c) => [c.id, c.nome]), t.cliente_id, "Sem cliente");
    cliente.addEventListener("change", () => salvar(t.id, { cliente_id: cliente.value }, true));
    const turno = selecao(Object.entries(TURNOS).filter(([k]) => k !== ""), t.turno || "", "Sem turno");
    turno.addEventListener("change", () => salvar(t.id, { turno: turno.value }, true));
    const etiqueta = el("input", { type: "text", value: (t.etiquetas || []).map((e) => e.nome).join(", "), placeholder: "separe por vírgula" });
    etiqueta.addEventListener("change", () => salvar(t.id, { etiquetas: etiqueta.value }, true));
    const descricao = el("textarea", { rows: "4", placeholder: "Descrição…" });
    descricao.value = t.descricao;
    descricao.addEventListener("change", () => salvar(t.id, { descricao: descricao.value }));

    const itens = el("div", {}, t.itens.map((i) => {
      const cb = el("input", { type: "checkbox", "aria-label": i.texto });
      cb.checked = i.feito;
      cb.addEventListener("change", async () => { try { await chamar("POST", "/api/itens/" + i.id, { feito: cb.checked }); await recarregar(); abrir(t.id); } catch (e) { toast(e.message, true); } });
      const x = el("button", { class: "btn btn-icone btn-pequeno", type: "button", "aria-label": "Remover item", text: "✕" });
      x.addEventListener("click", async () => { try { await chamar("POST", "/api/itens/" + i.id + "/excluir", {}); await recarregar(); abrir(t.id); } catch (e) { toast(e.message, true); } });
      return el("div", { class: "item-check" + (i.feito ? " is-feito" : "") }, [cb, el("span", { class: "grow", text: i.texto }), x]);
    }));
    const novoItem = el("form", { class: "linha-add" }, [el("input", { type: "text", placeholder: "Novo item do checklist", maxlength: "200" }), el("button", { class: "btn btn-contorno", type: "submit", text: "Adicionar" })]);
    novoItem.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      const texto = novoItem.querySelector("input").value.trim();
      if (!texto) return;
      try { await chamar("POST", "/api/tarefas/" + t.id + "/itens", { texto }); await recarregar(); abrir(t.id); } catch (e) { toast(e.message, true); }
    });

    const comentario = el("form", { class: "linha-add" }, [el("input", { type: "text", placeholder: "Escreva um comentário…", maxlength: "2000" }), el("button", { class: "btn btn-primario", type: "submit", text: "Comentar" })]);
    comentario.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      const texto = comentario.querySelector("input").value.trim();
      if (!texto) return;
      try { await chamar("POST", "/api/tarefas/" + t.id + "/comentarios", { texto }); abrir(t.id); } catch (e) { toast(e.message, true); }
    });

    const excluir = el("button", { class: "btn btn-perigo btn-pequeno", type: "button", text: "Excluir tarefa" });
    excluir.addEventListener("click", async () => {
      if (!window.confirm("Excluir esta tarefa?")) return;
      try { await chamar("POST", "/api/tarefas/" + t.id + "/excluir", {}); fechar(); await recarregar(); } catch (e) { toast(e.message, true); }
    });
    const fecharBtn = el("button", { class: "btn btn-icone", type: "button", "aria-label": "Fechar", text: "✕" });
    fecharBtn.addEventListener("click", fechar);

    ui.gaveta.replaceChildren(
      el("div", { class: "gaveta-topo" }, [el("span", { class: "selo", text: lista.nome }), el("div", { class: "topo-acoes" }, [excluir, fecharBtn])]),
      titulo,
      el("div", { class: "grade-props" }, [campo("Status", status), campo("Responsável", resp), campo("Prioridade", prio), campo("Prazo", data), campo("Cliente", cliente), campo("Turno da rotina", turno)]),
      campo("Etiquetas", etiqueta),
      campo("Descrição", descricao),
      el("div", {}, [el("h2", { text: "Checklist" }), itens, novoItem]),
      el("div", {}, [el("h2", { text: "Atividade" }), ...t.atividades.map((a) => a.tipo === "comentario"
        ? el("div", { class: "atividade-comentario" }, [el("strong", { text: a.autor }), el("div", { text: a.texto })])
        : el("div", { class: "atividade" }, [el("i", { class: "ponto" }), el("div", {}, [el("span", { text: a.autor + " " + textoAtividade(a) }), el("br"), el("small", { text: a.criado_em.slice(8, 10) + "/" + a.criado_em.slice(5, 7) + " " + a.criado_em.slice(11, 16) })])])), comentario]),
    );
    ui.gaveta.hidden = false;
    ui.fundo.hidden = false;
  }

  function textoAtividade(a) {
    const rotulos = { criada: "criou a tarefa", status: "mudou o status:", prioridade: "mudou a prioridade:", prazo: "mudou o prazo:", responsavel: "mudou o responsável:", titulo: "renomeou:", descricao: "editou a descrição", cliente: "mudou o cliente:", turno: "mudou o turno:", etiquetas: "definiu as etiquetas:" };
    return (rotulos[a.tipo] || a.tipo) + (a.texto ? " " + a.texto : "");
  }

  // ---------- nova tarefa ----------
  function novaTarefa() {
    const titulo = el("input", { type: "text", maxlength: "200", placeholder: "O que precisa ser feito?", "aria-label": "Título" });
    const lista = el("select"); selecionar(lista, state.meta.listas.map((l) => [l.id, l.nome]), (state.meta.listas.find((l) => l.id !== 1) || state.meta.listas[0]).id);
    const resp = el("select"); selecionar(resp, state.meta.usuarios.map((u) => [u.id, u.nome]), state.meta.usuario.id, "Sem responsável");
    const prio = el("select"); selecionar(prio, state.meta.prioridades.map((p) => [p.id, p.nome]), 3);
    const data = el("input", { type: "date" });
    const cliente = el("select"); selecionar(cliente, state.meta.clientes.map((c) => [c.id, c.nome]), "", "Sem cliente");
    const criar = el("button", { class: "btn btn-primario", type: "submit", text: "Criar tarefa" });
    const fecharBtn = el("button", { class: "btn btn-icone", type: "button", "aria-label": "Fechar", text: "✕" });
    fecharBtn.addEventListener("click", fechar);
    const form = el("form", { class: "form-pilha" }, [campo("Título", titulo), el("div", { class: "grade-props" }, [campo("Lista", lista), campo("Responsável", resp), campo("Prioridade", prio), campo("Prazo", data), campo("Cliente", cliente)]), criar]);
    form.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      try {
        const r = await chamar("POST", "/api/tarefas", { titulo: titulo.value, lista_id: lista.value, responsavel_id: resp.value, prioridade: prio.value, prazo: data.value, cliente_id: cliente.value });
        await recarregar(); abrir(r.id); toast("Tarefa criada.");
      } catch (e) { toast(e.message, true); }
    });
    ui.gaveta.replaceChildren(el("div", { class: "gaveta-topo" }, [el("h2", { text: "Nova tarefa" }), fecharBtn]), form);
    ui.gaveta.hidden = false; ui.fundo.hidden = false;
    titulo.focus();
  }

  // ---------- eventos ----------
  ui.conteudo.addEventListener("click", (ev) => {
    const concluir = ev.target.closest("[data-concluir]");
    if (concluir) { const t = state.tarefas.find((x) => x.id === parseInt(concluir.dataset.concluir, 10)); return t && alternarFeita(t, ev); }
    const alvo = ev.target.closest("[data-id]");
    if (alvo) abrir(parseInt(alvo.dataset.id, 10));
  });
  ui.conteudo.addEventListener("keydown", (ev) => { if ((ev.key === "Enter" || ev.key === " ") && ev.target.matches("[data-id]")) { ev.preventDefault(); abrir(parseInt(ev.target.dataset.id, 10)); } });
  ui.conteudo.addEventListener("submit", async (ev) => {
    if (ev.target.id !== "rapido") return;
    ev.preventDefault();
    const campoTitulo = $("rapidoTitulo");
    if (!campoTitulo.value.trim()) return;
    try { await chamar("POST", "/api/tarefas", { titulo: campoTitulo.value, lista_id: $("rapidoLista").value }); toast("Tarefa criada."); await recarregar(); $("rapidoTitulo") && $("rapidoTitulo").focus(); }
    catch (e) { toast(e.message, true); }
  });

  // arrastar e soltar no quadro
  ui.conteudo.addEventListener("dragstart", (ev) => { const c = ev.target.closest(".card-tarefa"); if (c) { state.arrastando = parseInt(c.dataset.id, 10); c.classList.add("is-arrastando"); ev.dataTransfer.effectAllowed = "move"; ev.dataTransfer.setData("text/plain", c.dataset.id); } });
  ui.conteudo.addEventListener("dragend", () => { state.arrastando = null; ui.conteudo.querySelectorAll(".is-arrastando, .is-alvo").forEach((n) => n.classList.remove("is-arrastando", "is-alvo")); });
  ui.conteudo.addEventListener("dragover", (ev) => { const col = ev.target.closest(".coluna-quadro"); if (col && state.arrastando) { ev.preventDefault(); ui.conteudo.querySelectorAll(".is-alvo").forEach((n) => n.classList.remove("is-alvo")); col.classList.add("is-alvo"); } });
  ui.conteudo.addEventListener("drop", (ev) => {
    const col = ev.target.closest(".coluna-quadro");
    if (!col || !state.arrastando) return;
    ev.preventDefault();
    const t = state.tarefas.find((x) => x.id === state.arrastando);
    const novo = parseInt(col.dataset.status, 10);
    if (t && t.status_id !== novo) { const s = listaPor(t.lista_id).status.find((x) => x.id === novo); t.status_id = novo; t.status_tipo = s.tipo; desenhar(); mudarStatus(t.id, novo); }
  });

  raiz.querySelectorAll("[data-vista]").forEach((b) => b.addEventListener("click", () => {
    state.vista = b.dataset.vista;
    raiz.querySelectorAll("[data-vista]").forEach((x) => x.classList.toggle("is-ativo", x === b));
    desenhar();
  }));
  [ui.lista, ui.resp, ui.prio, ui.cliente, ui.abertas].forEach((c) => c.addEventListener("change", recarregar));
  let espera = null;
  ui.busca.addEventListener("input", () => { clearTimeout(espera); espera = setTimeout(recarregar, 250); });
  $("tarefaNova").addEventListener("click", novaTarefa);
  ui.fundo.addEventListener("click", fechar);
  document.addEventListener("keydown", (ev) => { if (ev.key === "Escape" && !ui.gaveta.hidden) fechar(); });

  iniciar().catch((e) => toast(e.message, true));
})();
