// Automacoes: lista com liga/desliga, executar agora, historico e construtor "quando... entao...".
(function () {
  "use strict";

  const raiz = document.getElementById("automacoes");
  if (!raiz) return;
  const { el, toast, chamar } = window.Rotina;
  const admin = raiz.dataset.papel === "admin";
  const $ = (id) => document.getElementById(id);
  const ui = { lista: $("autoLista"), gaveta: $("gaveta"), fundo: $("gavetaFundo") };
  const state = { meta: null, automacoes: [], execucoes: {} };

  const DIAS = [["segunda", "Seg"], ["terca", "Ter"], ["quarta", "Qua"], ["quinta", "Qui"], ["sexta", "Sex"]];
  const TURNOS = [["", "Sem turno"], ["cliente", "O turno do cliente"], ["manha", "08h · Manhã"], ["tarde", "13h · Tarde"], ["fechamento", "17h · Fechamento"]];
  const TIPOS_STATUS = [["aberto", "A fazer"], ["andamento", "Em andamento"], ["fechado", "Concluído"]];
  const ACOES = [["criar_tarefa", "Criar tarefa"], ["mudar_status", "Mudar status"], ["atribuir", "Atribuir a alguém"], ["definir_prioridade", "Definir prioridade"], ["adicionar_etiqueta", "Adicionar etiqueta"], ["comentar", "Comentar"], ["adicionar_item", "Adicionar item ao checklist"]];
  const POR = [["unica", "Uma tarefa"], ["cliente_agendado", "Uma para cada cliente agendado no dia"], ["gestor", "Uma para cada gestor ativo"]];
  const PRAZOS = [["", "Sem prazo"], ["hoje", "Hoje"], ["+1", "Amanhã (+1 dia)"], ["+2", "+2 dias"], ["+3", "+3 dias"], ["+7", "+7 dias"]];
  const nomeLista = (id) => (state.meta.listas.find((l) => l.id === id) || {}).nome || "lista " + id;
  const nomeUsuario = (id) => (state.meta.usuarios.find((u) => u.id === id) || {}).nome || "#" + id;

  function opcoes(sel, itens, valor) {
    sel.replaceChildren(...itens.map(([v, t]) => el("option", { value: String(v), text: t })));
    if (valor !== undefined && valor !== null) sel.value = String(valor);
    return sel;
  }
  const campo = (rotulo, controle) => el("label", {}, [el("span", { text: rotulo }), controle]);

  // ---------- resumo legivel ----------
  function textoGatilho(a) {
    const c = a.gatilho_config || {};
    if (a.gatilho === "agenda") return (c.dias || []).map((d) => (DIAS.find((x) => x[0] === d) || [0, d])[1]).join(", ") + " às " + c.hora;
    if (a.gatilho === "status_mudou") return "Quando o status for para " + (c.para_tipo ? TIPOS_STATUS.find((x) => x[0] === c.para_tipo)[1] : "qualquer um");
    return state.meta.gatilhos[a.gatilho];
  }

  function textoAcao(x) {
    switch (x.tipo) {
      case "criar_tarefa": return "Criar “" + x.titulo + "”" + (x.por === "cliente_agendado" ? " por cliente" : x.por === "gestor" ? " por gestor" : "") + " em " + nomeLista(x.lista_id);
      case "mudar_status": return "Mudar status para " + (x.tipo_status ? TIPOS_STATUS.find((t) => t[0] === x.tipo_status)[1] : "#" + x.status_id);
      case "atribuir": return "Atribuir a " + (x.usuario_id === "gestor_do_cliente" ? "gestor do cliente" : nomeUsuario(x.usuario_id));
      case "definir_prioridade": return "Prioridade " + (state.meta.prioridades.find((p) => p.id === x.prioridade) || {}).nome;
      case "adicionar_etiqueta": return "Etiqueta “" + x.nome + "”";
      case "comentar": return "Comentar “" + x.texto + "”";
      default: return "Item “" + x.texto + "”";
    }
  }

  // ---------- lista ----------
  async function carregar() {
    state.automacoes = (await chamar("GET", "/api/automacoes")).automacoes;
    desenhar();
  }

  function desenhar() {
    ui.lista.replaceChildren(...state.automacoes.map(card));
    if (!state.automacoes.length) ui.lista.replaceChildren(el("p", { class: "muted", text: "Nenhuma automação ainda." }));
  }

  function card(a) {
    const liga = el("button", { class: "interruptor", type: "button", role: "switch", "aria-checked": a.ativa ? "true" : "false", "aria-label": "Ligar ou desligar " + a.nome, disabled: !admin });
    liga.addEventListener("click", async () => {
      try { await chamar("POST", "/api/automacoes/" + a.id + "/alternar", { ativa: !a.ativa }); await carregar(); } catch (e) { toast(e.message, true); }
    });
    const botoes = [];
    if (admin && (a.gatilho === "agenda" || a.gatilho === "prazo_vencido")) {
      const b = el("button", { class: "btn btn-suave btn-pequeno", type: "button", text: "Executar agora" });
      b.addEventListener("click", async () => { try { const r = await chamar("POST", "/api/automacoes/" + a.id + "/executar", {}); toast(r.tarefas_criadas + " tarefa(s) criada(s)."); await carregar(); } catch (e) { toast(e.message, true); } });
      botoes.push(b);
    }
    const hist = el("button", { class: "btn btn-suave btn-pequeno", type: "button", text: "Histórico" });
    hist.addEventListener("click", async () => {
      if (state.execucoes[a.id]) { delete state.execucoes[a.id]; return desenhar(); }
      try { state.execucoes[a.id] = (await chamar("GET", "/api/automacoes/" + a.id + "/execucoes")).execucoes; desenhar(); } catch (e) { toast(e.message, true); }
    });
    botoes.push(hist);

    if (admin) {
      const ed = el("button", { class: "btn btn-suave btn-pequeno", type: "button", text: "Editar" });
      ed.addEventListener("click", () => editar(a));
      const ex = el("button", { class: "btn btn-suave perigo btn-pequeno", type: "button", text: "Excluir" });
      ex.addEventListener("click", async () => {
        if (!window.confirm("Excluir a automação “" + a.nome + "”?")) return;
        try { await chamar("POST", "/api/automacoes/" + a.id + "/excluir", {}); await carregar(); } catch (e) { toast(e.message, true); }
      });
      botoes.push(ed, ex);
    }

    const historico = state.execucoes[a.id]
      ? el("div", { class: "grow" }, state.execucoes[a.id].length ? state.execucoes[a.id].map((x) => el("div", { class: "exec" }, [el("span", { text: x.resumo || x.chave }), el("span", { text: x.criado_em.slice(8, 10) + "/" + x.criado_em.slice(5, 7) + " " + x.criado_em.slice(11, 16) })])) : [el("p", { class: "muted", text: "Ainda não rodou." })])
      : null;

    return el("div", { class: "cartao" }, [
      el("div", { class: "auto-card" }, [
        el("span", { class: "auto-icone" }, [el("span", { text: a.gatilho === "agenda" ? "⏰" : a.gatilho === "prazo_vencido" ? "⚠" : "⚡" })]),
        el("div", {}, [el("strong", { text: a.nome }),
          el("div", { class: "auto-resumo" }, [
            el("span", { class: "auto-chip" }, [el("b", { text: "Quando: " }), textoGatilho(a)]),
            el("span", { class: "auto-chip", title: a.acoes.map(textoAcao).join("\n") }, [el("b", { text: "Então: " }), a.acoes.length > 1 ? a.acoes.length + " ações (" + textoAcao(a.acoes[0]) + "…)" : textoAcao(a.acoes[0])]),
          ])]),
        el("div", { class: "auto-acoes" }, [liga]),
      ]),
      el("div", { class: "auto-rodape" }, [
        el("small", { class: "muted", text: a.ultima_execucao ? "Última execução: " + a.ultima_execucao.slice(8, 10) + "/" + a.ultima_execucao.slice(5, 7) + " " + a.ultima_execucao.slice(11, 16) : "Ainda não rodou" }),
        ...botoes,
      ]),
      historico,
    ]);
  }

  // ---------- construtor ----------
  let rascunho = null;
  let editandoId = null;

  const novaAcao = () => ({ tipo: "comentar", texto: "" });
  function novo() { editandoId = null; rascunho = { nome: "", ativa: true, gatilho: "status_mudou", gatilho_config: { para_tipo: "fechado" }, condicoes: {}, acoes: [novaAcao()] }; construtor(); }
  function editar(a) { editandoId = a.id; rascunho = JSON.parse(JSON.stringify(a)); construtor(); }
  function fechar() { ui.gaveta.hidden = true; ui.fundo.hidden = true; }

  function blocoAcao(x, i) {
    const tipo = opcoes(el("select"), ACOES, x.tipo);
    tipo.addEventListener("change", () => {
      const base = { tipo: tipo.value };
      if (base.tipo === "criar_tarefa") Object.assign(base, { lista_id: state.meta.listas[0].id, titulo: "", por: "unica", turno: "", prazo: "hoje", prioridade: 3, responsavel: "" });
      if (base.tipo === "mudar_status") base.tipo_status = "fechado";
      if (base.tipo === "atribuir") base.usuario_id = state.meta.usuarios[0].id;
      if (base.tipo === "definir_prioridade") base.prioridade = 2;
      if (["adicionar_etiqueta"].includes(base.tipo)) base.nome = "";
      if (["comentar", "adicionar_item"].includes(base.tipo)) base.texto = "";
      rascunho.acoes[i] = base; construtor();
    });
    const campos = [];
    const texto = (rot, chave, ph) => { const inp = el("input", { type: "text", value: x[chave] || "", placeholder: ph || "", maxlength: "200" }); inp.addEventListener("input", () => { x[chave] = inp.value; }); return campo(rot, inp); };
    const escolha = (rot, chave, itens, valor) => { const s = opcoes(el("select"), itens, valor); s.addEventListener("change", () => { x[chave] = s.value; }); return campo(rot, s); };

    if (x.tipo === "criar_tarefa") {
      campos.push(escolha("Lista", "lista_id", state.meta.listas.map((l) => [l.id, l.nome]), x.lista_id));
      campos.push(texto("Título (use {cliente}, {tarefa}, {data}, {gestor})", "titulo", "Ex.: Analisar {cliente}"));
      if (rascunho.gatilho === "agenda") campos.push(escolha("Para quem criar", "por", POR, x.por || "unica"));
      campos.push(escolha("Turno", "turno", TURNOS, x.turno || ""));
      campos.push(escolha("Prazo", "prazo", PRAZOS, x.prazo || ""));
      campos.push(escolha("Prioridade", "prioridade", state.meta.prioridades.map((p) => [p.id, p.nome]), x.prioridade || 3));
      campos.push(escolha("Responsável", "responsavel", [["", "Quem for o alvo (cliente/gestor)"], ["gatilho", "Mesmo responsável da tarefa do gatilho"], ["gestor_do_cliente", "Gestor do cliente"], ...state.meta.usuarios.map((u) => [u.id, u.nome])], x.responsavel || ""));
    } else if (x.tipo === "mudar_status") campos.push(escolha("Novo status", "tipo_status", TIPOS_STATUS, x.tipo_status || "fechado"));
    else if (x.tipo === "atribuir") campos.push(escolha("Atribuir a", "usuario_id", [["gestor_do_cliente", "Gestor do cliente"], ...state.meta.usuarios.map((u) => [u.id, u.nome])], x.usuario_id));
    else if (x.tipo === "definir_prioridade") campos.push(escolha("Prioridade", "prioridade", state.meta.prioridades.map((p) => [p.id, p.nome]), x.prioridade));
    else if (x.tipo === "adicionar_etiqueta") campos.push(texto("Nome da etiqueta", "nome", "Ex.: atrasada"));
    else campos.push(texto(x.tipo === "comentar" ? "Texto do comentário" : "Texto do item", "texto", "Pode usar {cliente}, {tarefa}, {data}, {gestor}"));

    const remover = el("button", { class: "btn btn-perigo btn-pequeno", type: "button", text: "Remover", disabled: rascunho.acoes.length === 1 });
    remover.addEventListener("click", () => { rascunho.acoes.splice(i, 1); construtor(); });
    return el("div", { class: "bloco" }, [el("div", { class: "bloco-titulo" }, [el("span", {}, [el("span", { class: "num", text: String(i + 1) }), "Então"]), remover]), campo("Ação", tipo), ...campos]);
  }

  function construtor() {
    const nome = el("input", { type: "text", value: rascunho.nome, maxlength: "120", placeholder: "Ex.: Ao concluir, avisar no comentário" });
    nome.addEventListener("input", () => { rascunho.nome = nome.value; });
    const gatilho = opcoes(el("select"), Object.entries(state.meta.gatilhos), rascunho.gatilho);
    gatilho.addEventListener("change", () => {
      rascunho.gatilho = gatilho.value;
      rascunho.gatilho_config = gatilho.value === "agenda" ? { dias: ["segunda", "terca", "quarta", "quinta", "sexta"], hora: "05:00" } : gatilho.value === "status_mudou" ? { para_tipo: "fechado" } : {};
      if (gatilho.value !== "agenda") rascunho.acoes.forEach((a) => { if (a.tipo === "criar_tarefa") a.por = "unica"; });
      construtor();
    });
    const cfg = [];

    if (rascunho.gatilho === "agenda") {
      const marcados = rascunho.gatilho_config.dias || [];
      const dias = el("div", { class: "dias" }, [el("legend", { text: "Dias" }), ...DIAS.map(([k, r]) => {
        const cb = el("input", { type: "checkbox" }); cb.checked = marcados.includes(k);
        cb.addEventListener("change", () => { rascunho.gatilho_config.dias = DIAS.map((d) => d[0]).filter((d) => (d === k ? cb.checked : (rascunho.gatilho_config.dias || []).includes(d))); });
        return el("label", { class: "check" }, [cb, r]);
      })]);
      const hora = el("input", { type: "time", value: rascunho.gatilho_config.hora || "05:00" });
      hora.addEventListener("input", () => { rascunho.gatilho_config.hora = hora.value; });
      cfg.push(dias, campo("Hora", hora));
    } else if (rascunho.gatilho === "status_mudou") {
      const s = opcoes(el("select"), [["", "Qualquer status"], ...TIPOS_STATUS], rascunho.gatilho_config.para_tipo || "");
      s.addEventListener("change", () => { rascunho.gatilho_config = s.value ? { para_tipo: s.value } : {}; });
      cfg.push(campo("Quando o status for para", s));
    }

    const condicoes = [];

    if (rascunho.gatilho !== "agenda") {
      const lista = opcoes(el("select"), [["", "Qualquer lista"], ...state.meta.listas.map((l) => [l.id, l.nome])], rascunho.condicoes.lista_id || "");
      lista.addEventListener("change", () => { if (lista.value) rascunho.condicoes.lista_id = parseInt(lista.value, 10); else delete rascunho.condicoes.lista_id; });
      const prio = opcoes(el("select"), [["", "Qualquer prioridade"], ...state.meta.prioridades.map((p) => [p.id, p.nome])], rascunho.condicoes.prioridade || "");
      prio.addEventListener("change", () => { if (prio.value) rascunho.condicoes.prioridade = parseInt(prio.value, 10); else delete rascunho.condicoes.prioridade; });
      condicoes.push(el("div", { class: "bloco" }, [el("div", { class: "bloco-titulo" }, [el("span", { text: "Somente se (opcional)" })]), campo("Lista", lista), campo("Prioridade", prio)]));
    }

    const adicionar = el("button", { class: "btn btn-contorno", type: "button" }, [el("span", { text: "+ Adicionar ação" })]);
    adicionar.addEventListener("click", () => { rascunho.acoes.push(novaAcao()); construtor(); });
    const salvar = el("button", { class: "btn btn-primario", type: "button", text: editandoId ? "Salvar alterações" : "Criar automação" });
    salvar.addEventListener("click", async () => {
      try {
        await chamar("POST", editandoId ? "/api/automacoes/" + editandoId : "/api/automacoes", rascunho);
        toast("Automação salva."); fechar(); await carregar();
      } catch (e) { toast(e.message, true); }
    });
    const cancelar = el("button", { class: "btn btn-icone", type: "button", "aria-label": "Fechar", text: "✕" });
    cancelar.addEventListener("click", fechar);

    ui.gaveta.replaceChildren(
      el("div", { class: "gaveta-topo" }, [el("h2", { text: editandoId ? "Editar automação" : "Nova automação" }), cancelar]),
      campo("Nome", nome),
      el("div", { class: "bloco" }, [el("div", { class: "bloco-titulo" }, [el("span", {}, [el("span", { class: "num", text: "⚡" }), "Quando"])]), campo("Gatilho", gatilho), ...cfg]),
      ...condicoes,
      ...rascunho.acoes.map(blocoAcao),
      adicionar,
      el("div", { class: "topo-acoes" }, [salvar]),
    );
    ui.gaveta.hidden = false;
    ui.fundo.hidden = false;
  }

  ui.fundo.addEventListener("click", fechar);
  document.addEventListener("keydown", (ev) => { if (ev.key === "Escape" && !ui.gaveta.hidden) fechar(); });
  if ($("autoNova")) $("autoNova").addEventListener("click", novo);

  chamar("GET", "/api/meta").then((m) => { state.meta = m; return carregar(); }).catch((e) => toast(e.message, true));
})();
