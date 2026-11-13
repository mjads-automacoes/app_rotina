// Rotina do gestor (modo foco). Dados de /api/rotina; tudo otimista: a tela muda na hora e confirma no servidor.
(function () {
  "use strict";

  const raiz = document.getElementById("rotina");
  if (!raiz) return;
  const { el, toast, chamar } = window.Rotina;

  const DIAS = { segunda: "Segunda", terca: "Terça", quarta: "Quarta", quinta: "Quinta", sexta: "Sexta" };
  const TURNOS = [["manha", "Manhã", "da manhã"], ["tarde", "Tarde", "da tarde"]];
  const STATUS = { aberto: "A fazer", andamento: "Em andamento", fechado: "Concluído" };
  const $ = (id) => document.getElementById(id);
  const ui = { texto: $("rotinaProgressoTexto"), barra: $("rotinaBarra"), barraWrap: $("rotinaBarraWrap"), dias: $("rotinaDias"), erro: $("rotinaErro"),
    checklist: $("rotinaChecklist"), foco: $("rotinaFoco"), clientes: $("rotinaClientes"), gestor: $("rotinaGestor"), atualizar: $("rotinaAtualizar") };
  const state = { enviadas: {}, dados: null, data: null, turno: null, foco: null, nota: "", pendentes: 0 };

  // so existe a rotina de hoje: sem dia da semana para escolher (fim de semana = dia vazio)
  const VAZIO = { dia: "", turnos: { manha: { clientes: [], checklist: [] }, tarde: { clientes: [], checklist: [] } } };
  const dia = () => state.dados.semana[state.data] || VAZIO;
  const clientesDo = (turno, d) => (d || dia()).turnos[turno].clientes;
  const checklistDo = (turno, d) => (d || dia()).turnos[turno].checklist;
  const todosClientes = (d) => TURNOS.flatMap(([t]) => clientesDo(t, d));
  const tarefaPorId = (id) => {
    for (const d of Object.values(state.dados.semana)) for (const [t] of TURNOS) for (const x of [...clientesDo(t, d), ...checklistDo(t, d)]) if (x.id === id) return x;
    return null;
  };

  function escolherInicio() {
    const datas = Object.keys(state.dados.semana);
    state.data = raiz.dataset.hoje;
    const hora = parseInt(raiz.dataset.hora || "9", 10);
    state.turno = hora >= 13 ? "tarde" : "manha";
  }

  // sem filtro de turno: o foco vai para o primeiro cliente pendente do dia (manha primeiro); o turno acompanha o cliente em foco
  function escolherFoco() {
    for (const [t] of TURNOS) {
      if (clientesDo(t).some((c) => c.id === state.foco)) { state.turno = t; return; }
    }
    const todos = TURNOS.flatMap(([t]) => clientesDo(t).map((c) => [t, c]));
    const proximo = todos.find(([, c]) => c.status_tipo !== "fechado") || todos[0];
    state.foco = proximo ? proximo[1].id : null;
    if (proximo) state.turno = proximo[0];
  }

  // ficha do cliente em janela (pop-up) sobre a Rotina; ao fechar, a Rotina recarrega para refletir o que mudou
  function abrirFicha(c) {
    const fechar = () => { fundo.remove(); document.removeEventListener("keydown", tecla); carregar(true); };
    const tecla = (ev) => { if (ev.key === "Escape") fechar(); };
    const fundo = el("div", { class: "janela-fundo" }, [
      el("div", { class: "janela", role: "dialog", "aria-modal": "true", "aria-label": "Ficha do cliente" }, [
        el("div", { class: "janela-topo" }, [el("strong", { text: c.cliente_nome || c.titulo }), el("button", { class: "btn btn-icone", type: "button", "aria-label": "Fechar", text: "✕" })]),
        el("iframe", { class: "janela-quadro", src: "/clientes/" + c.cliente_id, title: "Ficha do cliente" }),
      ]),
    ]);
    fundo.querySelector("button").addEventListener("click", fechar);
    fundo.addEventListener("mousedown", (ev) => { if (ev.target === fundo) fechar(); });
    document.addEventListener("keydown", tecla);
    document.body.append(fundo);
  }

  async function carregar(silencioso) {
    const gestor = ui.gestor ? "&gestor=" + encodeURIComponent(ui.gestor.value) : "";
    try {
      const dados = await chamar("GET", "/api/rotina?data=" + encodeURIComponent(raiz.dataset.hoje) + gestor);
      if (state.pendentes > 0) return;
      const primeira = state.dados === null;
      state.dados = dados;
      ui.erro.hidden = true;
      if (primeira) escolherInicio();
      renderizar();
    } catch (e) {
      if (!silencioso) { ui.erro.textContent = e.message; ui.erro.hidden = false; }
    }
  }

  // botao so com o icone de enviar (aviao de papel); o SVG e fixo, sem conteudo do usuario
  function iconeEnviar() {
    const b = el("button", { class: "btn btn-primario btn-enviar", type: "button", "data-acao": "enviar-nota", title: "Enviar nota", "aria-label": "Enviar nota" });
    b.innerHTML = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
    return b;
  }

  // envia a nota agora (como comentario da tarefa), sem concluir; fica listada abaixo do campo
  async function enviarNota(c, botao) {
    const texto = state.nota.trim();
    if (!texto) { toast("Escreva a nota antes de enviar.", true); return; }
    botao.disabled = true;
    try {
      await chamar("POST", "/api/tarefas/" + c.id + "/comentarios", { texto });
      (state.enviadas[c.id] = state.enviadas[c.id] || []).push(texto);
      state.nota = "";
      toast("Nota enviada.");
      renderizar();
      const campo = $("rotinaNota");
      if (campo) campo.focus();
    } catch (e) { toast(e.message, true); botao.disabled = false; }
  }

  async function acao(tarefa, tipo, rota, extra) {
    const anterior = tarefa.status_tipo;
    tarefa.status_tipo = tipo;
    tarefa.status_nome = STATUS[tipo];
    if (tipo === "fechado") state.foco = null;
    renderizar();
    state.pendentes++;
    try {
      await chamar("POST", "/api/rotina/" + rota, Object.assign({ tarefa_id: tarefa.id }, extra || {}));
    } catch (e) {
      tarefa.status_tipo = anterior;
      tarefa.status_nome = STATUS[anterior];
      toast(e.message, true);
      renderizar();
    } finally { state.pendentes--; }
  }

  // ---------- desenho ----------
  function topo() {
    const d = dia();
    const feitas = TURNOS.reduce((s, [t]) => s + clientesDo(t, d).filter((c) => c.status_tipo === "fechado").length + checklistDo(t, d).filter((c) => c.status_tipo === "fechado").length, 0);
    const total = TURNOS.reduce((s, [t]) => s + clientesDo(t, d).length + checklistDo(t, d).length, 0);
    const nome = { segunda: "Segunda-feira", terca: "Terça-feira", quarta: "Quarta-feira", quinta: "Quinta-feira", sexta: "Sexta-feira" }[d.dia] || "Hoje";
    ui.dias.replaceChildren(...[el("strong", { text: nome }), el("span", { class: "muted", text: state.data.slice(8, 10) + "/" + state.data.slice(5, 7) }), total ? el("small", { text: feitas + "/" + total + " feitos" }) : null].filter(Boolean));
  }

  function checklist() {
    const todos = TURNOS.flatMap(([t]) => checklistDo(t));
    const feitas = todos.filter((i) => i.status_tipo === "fechado").length;
    const grupos = TURNOS.filter(([t]) => checklistDo(t).length > 0).map(([t, rotulo]) => el("div", { class: "rotina-grupo" }, [
      el("p", { class: "rotina-subtitulo", text: rotulo }),
      el("div", { class: "rotina-itens", role: "group", "aria-label": rotulo }, checklistDo(t).map((i) => {
        const feito = i.status_tipo === "fechado";
        return el("button", { class: "rotina-item" + (feito ? " is-feito" : ""), type: "button", role: "checkbox", "aria-checked": feito ? "true" : "false", "data-item": i.id },
          [el("span", { class: "rotina-item-texto", text: i.titulo }), el("span", { class: "rotina-check", text: feito ? "✓" : "" })]);
      })),
    ]));
    ui.checklist.replaceChildren(
      el("div", { class: "cartao-topo" }, [el("h3", { text: "Check List do Dia" }), el("span", { class: "contagem", text: feitas + " de " + todos.length })]),
      ...(grupos.length ? grupos : [el("p", { class: "muted", text: "Sem itens hoje." })]),
    );
  }

  function foco() {
    const cliente = clientesDo(state.turno).find((c) => c.id === state.foco);
    if (!cliente) {
      const vazioDia = TURNOS.every(([t]) => clientesDo(t).length + checklistDo(t).length === 0);
      const hoje = state.data === raiz.dataset.hoje;
      ui.foco.replaceChildren(el("div", { class: "rotina-vazio" }, [
        el("span", { class: "rotina-vazio-icone", text: vazioDia ? "📅" : "☕" }),
        el("strong", { text: vazioDia ? "Nada programado para este dia." : "Nenhum cliente hoje." }),
        vazioDia && hoje ? el("span", { text: "A rotina é criada pelas automações às 05:00. Se ainda não rodou, gere agora." }) : null,
        vazioDia && hoje ? el("button", { class: "btn btn-primario", type: "button", "data-acao": "gerar", text: "Gerar rotina de hoje" }) : null,
      ]));
      return;
    }
    const tipo = cliente.status_tipo;
    ui.foco.replaceChildren(...[
      el("span", { class: "rotina-selo" + (tipo === "fechado" ? " is-ok" : ""), text: tipo === "fechado" ? "CONCLUÍDO" : "EM FOCO" }),
      el("div", { class: "rotina-foco-linha" }, [el("h2", { class: "rotina-foco-nome", text: cliente.cliente_nome || cliente.titulo }), el("span", { class: "pilula is-" + tipo, text: STATUS[tipo] || cliente.status_nome })]),
      cliente.cliente_descricao ? el("p", { class: "rotina-foco-descricao", text: cliente.cliente_descricao }) : null,
      tipo === "fechado" ? null : el("div", { class: "rotina-foco-nota" }, [
        el("label", { for: "rotinaNota", text: "Nota da Tarefa" }),
        el("div", { class: "nota-linha" }, [
          el("input", { type: "text", id: "rotinaNota", maxlength: "1000", value: state.nota, placeholder: "Ex.: ajustei o orçamento da campanha X", autocomplete: "off" }),
          iconeEnviar(),
        ]),
        ...(state.enviadas[cliente.id] || []).map((t) => el("p", { class: "nota-enviada", text: "✓ " + t })),
      ]),
      el("div", { class: "rotina-foco-acoes" }, [
        el("button", { class: "btn btn-contorno", type: "button", "data-acao": "abrir-cliente", text: "Ver Informações" }),
        tipo === "aberto" ? el("button", { class: "btn btn-primario", type: "button", "data-acao": "iniciar", text: "Iniciar" }) : null,
        tipo === "andamento" ? el("button", { class: "btn btn-primario", type: "button", "data-acao": "concluir", text: "Concluir" }) : null,
        tipo === "fechado" ? el("button", { class: "btn btn-contorno", type: "button", "data-acao": "reabrir", text: "Reabrir" }) : null,
      ]),
    ].filter(Boolean));
  }

  function clientes() {
    const partes = [el("h2", { text: "Clientes do dia" })];
    TURNOS.forEach(([t, rotulo]) => {
      const lista = clientesDo(t);
      partes.push(el("div", { class: "rotina-grupo" }, [
        el("div", { class: "rotina-grupo-topo", text: rotulo }),
        ...(lista.length ? lista.map((c) => el("button", { class: "rotina-cliente" + (c.id === state.foco && t === state.turno ? " is-foco" : ""), type: "button", "data-cliente": c.id, "data-turno": t }, [
          el("span", { class: "rotina-cliente-nome", text: c.cliente_nome || c.titulo }),
          el("span", { class: "rotina-cliente-status" }, [el("i", { class: "ponto", cor: c.status_tipo === "fechado" ? "var(--verde)" : c.status_tipo === "andamento" ? "var(--acento-2)" : "var(--muted)" }), el("span", { text: STATUS[c.status_tipo] })]),
        ])) : [el("p", { class: "rotina-cliente-vazio", text: "Sem clientes" })]),
      ]));
    });
    ui.clientes.replaceChildren(...partes);
  }

  function renderizar() {
    if (!state.dados) return;
    escolherFoco();
    const digitando = document.activeElement && document.activeElement.id === "rotinaNota";
    topo();
    checklist();
    foco();
    clientes();
    if (digitando) { const campo = $("rotinaNota"); if (campo) { campo.focus(); campo.setSelectionRange(campo.value.length, campo.value.length); } }
  }

  // ---------- eventos ----------
  raiz.addEventListener("click", async (event) => {
    if (!state.dados) return;
    const ir = event.target.closest(".ir-turno");
    if (ir) { state.turno = ir.dataset.irTurno; state.foco = null; return renderizar(); }
    const turno = event.target.closest(".turno");
    if (turno) { state.turno = turno.dataset.turno; state.foco = null; return renderizar(); }
    const cli = event.target.closest(".rotina-cliente");
    if (cli) { state.turno = cli.dataset.turno; state.foco = parseInt(cli.dataset.cliente, 10); return renderizar(); }
    const item = event.target.closest(".rotina-item");
    if (item) {
      const tarefa = tarefaPorId(parseInt(item.dataset.item, 10));
      return tarefa && acao(tarefa, tarefa.status_tipo === "fechado" ? "aberto" : "fechado", tarefa.status_tipo === "fechado" ? "reabrir" : "concluir");
    }
    const botao = event.target.closest("[data-acao]");
    if (!botao) return;
    if (botao.dataset.acao === "gerar") {
      botao.disabled = true;
      try { await chamar("POST", "/api/rotina/gerar", {}); toast("Rotina de hoje gerada."); await carregar(false); } catch (e) { toast(e.message, true); botao.disabled = false; }
      return;
    }
    const c = clientesDo(state.turno).find((x) => x.id === state.foco);
    if (!c) return;
    if (botao.dataset.acao === "abrir-cliente") return abrirFicha(c);
    if (botao.dataset.acao === "enviar-nota") return enviarNota(c, botao);
    if (botao.dataset.acao === "concluir") { const nota = state.nota; state.nota = ""; acao(c, "fechado", "concluir", { nota }); toast("Análise registrada."); }
    if (botao.dataset.acao === "iniciar") acao(c, "andamento", "iniciar");
    if (botao.dataset.acao === "reabrir") acao(c, "aberto", "reabrir");
  });

  raiz.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" || event.target.id !== "rotinaNota") return;
    event.preventDefault();
    const c = clientesDo(state.turno).find((x) => x.id === state.foco);
    const botao = raiz.querySelector('[data-acao="enviar-nota"]');
    if (c && botao) enviarNota(c, botao);
  });
  raiz.addEventListener("input", (event) => { if (event.target.id === "rotinaNota") state.nota = event.target.value; });
  if (ui.atualizar) ui.atualizar.addEventListener("click", () => carregar(false));
  if (ui.gestor) ui.gestor.addEventListener("change", () => { state.dados = null; state.foco = null; carregar(false); });

  carregar(false);
  setInterval(() => { if (!document.hidden) carregar(true); }, 60000);
})();
