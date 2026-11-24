// Rotina do gestor (modo foco). Dados de /api/rotina; tudo otimista: a tela muda na hora e confirma no servidor.
// (Ao conferir um deploy, olhe so o HTML de /login: buscar o arquivo com o ?v= novo antes do deploy terminar faz a Cloudflare guardar a versao velha.)
(function () {
  "use strict";

  const raiz = document.getElementById("rotina");
  if (!raiz) return;
  const { el, toast, chamar, confirmar } = window.Rotina;

  const DIAS = { segunda: "Segunda", terca: "Terça", quarta: "Quarta", quinta: "Quinta", sexta: "Sexta" };
  const TURNOS = [["manha", "Manhã", "da manhã"], ["tarde", "Tarde", "da tarde"]];
  // o checklist tem tambem o Fechamento (pedido do dono, 19/11/2026); os clientes continuam so manha e tarde
  const TURNOS_CHECK = [...TURNOS, ["fechamento", "Fechamento", "do fechamento"]];
  const STATUS = { aberto: "A fazer", andamento: "Em andamento", fechado: "Concluído" };
  const $ = (id) => document.getElementById(id);
  const ui = { texto: $("rotinaProgressoTexto"), barra: $("rotinaBarra"), barraWrap: $("rotinaBarraWrap"), dias: $("rotinaDias"), erro: $("rotinaErro"),
    checklist: $("rotinaChecklist"), foco: $("rotinaFoco"), clientes: $("rotinaClientes"), gestor: $("rotinaGestor"), atualizar: $("rotinaAtualizar") };
  const state = { dados: null, data: null, turno: null, foco: null, nota: "", pendentes: 0, versao: 0, tarefasCliente: {}, menuItem: null, editando: null, editTexto: "", adicionando: false, addTexto: "", addTurno: null };

  // so existe a rotina de hoje: sem dia da semana para escolher (fim de semana = dia vazio)
  const VAZIO = { dia: "", turnos: { manha: { clientes: [], checklist: [] }, tarde: { clientes: [], checklist: [] }, fechamento: { clientes: [], checklist: [] } } };
  const dia = () => state.dados.semana[state.data] || VAZIO;
  const clientesDo = (turno, d) => ((d || dia()).turnos[turno] || { clientes: [] }).clientes;
  const checklistDo = (turno, d) => ((d || dia()).turnos[turno] || { checklist: [] }).checklist;
  const todosClientes = (d) => TURNOS.flatMap(([t]) => clientesDo(t, d));
  const tarefaPorId = (id) => {
    for (const d of Object.values(state.dados.semana)) for (const [t] of TURNOS_CHECK) for (const x of [...clientesDo(t, d), ...checklistDo(t, d)]) if (x.id === id) return x;
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
    // lixeira ao lado do nome: exclui o cliente e as tarefas em aberto dele (as concluidas ficam, sem cliente) e fecha a janela
    const lixeira = el("button", { class: "btn btn-icone btn-lixeira", type: "button", "aria-label": "Excluir cliente", title: "Excluir cliente" });
    lixeira.innerHTML = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';
    lixeira.addEventListener("click", async () => {
      const nome = fundo.querySelector("[data-janela-nome]").textContent;
      if (!(await confirmar("Excluir o cliente " + nome + "? As tarefas em aberto dele também serão apagadas."))) return;
      try {
        await chamar("POST", "/api/clientes/" + c.cliente_id + "/excluir", {});
        toast("Cliente excluído.");
        state.foco = null;
        fechar();
      } catch (e) { toast(e.message, true); }
    });
    const fundo = el("div", { class: "janela-fundo" }, [
      el("div", { class: "janela", role: "dialog", "aria-modal": "true", "aria-label": "Ficha do cliente" }, [
        el("div", { class: "janela-topo" }, [
          el("div", { class: "janela-titulo" }, [el("strong", { "data-janela-nome": "", text: c.cliente_nome || c.titulo }), lixeira]),
          el("button", { class: "btn btn-icone", type: "button", "data-janela-fechar": "", "aria-label": "Fechar", text: "✕" }),
        ]),
        el("iframe", { class: "janela-quadro", src: "/clientes/" + c.cliente_id, title: "Ficha do cliente" }),
      ]),
    ]);
    fundo.querySelector("[data-janela-fechar]").addEventListener("click", fechar);
    fundo.addEventListener("mousedown", (ev) => { if (ev.target === fundo) fechar(); });
    document.addEventListener("keydown", tecla);
    document.body.append(fundo);
  }

  async function carregar(silencioso) {
    const gestor = ui.gestor ? "&gestor=" + encodeURIComponent(ui.gestor.value) : "";
    // uma acao feita enquanto a rotina carregava torna a resposta velha: descarta para nao desfazer o clique
    const versao = state.versao;
    try {
      const dados = await chamar("GET", "/api/rotina?data=" + encodeURIComponent(raiz.dataset.hoje) + gestor);
      if (state.pendentes > 0 || versao !== state.versao) return;
      const primeira = state.dados === null;
      state.dados = dados;
      Object.values(state.tarefasCliente).forEach((x) => { x.velha = true; });
      ui.erro.hidden = true;
      if (primeira) escolherInicio();
      renderizar();
    } catch (e) {
      if (!silencioso) toast(e.message, true);
    }
  }

  // botao so com o icone de enviar (aviao de papel); o SVG e fixo, sem conteudo do usuario
  function iconeEnviar() {
    const b = el("button", { class: "btn btn-primario btn-enviar", type: "button", "data-acao": "nova-tarefa", title: "Criar tarefa", "aria-label": "Criar tarefa" });
    b.innerHTML = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
    return b;
  }

  // cria uma nova tarefa para o cliente em foco (prazo hoje); ela aparece na lista "Tarefas do cliente"
  async function criarTarefa(c, botao) {
    const titulo = state.nota.trim();
    if (!titulo) { toast("Escreva a tarefa antes de enviar.", true); return; }
    if (!c.cliente_id) return;
    botao.disabled = true;
    try {
      await chamar("POST", "/api/tarefas", { titulo, cliente_id: c.cliente_id, lista_id: 2, prazo: raiz.dataset.hoje });
      if (state.tarefasCliente[c.cliente_id]) state.tarefasCliente[c.cliente_id].velha = true;
      state.nota = "";
      toast("Tarefa criada.");
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
    state.versao++;
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
    const feitas = TURNOS_CHECK.reduce((s, [t]) => s + clientesDo(t, d).filter((c) => c.status_tipo === "fechado").length + checklistDo(t, d).filter((c) => c.status_tipo === "fechado").length, 0);
    const total = TURNOS_CHECK.reduce((s, [t]) => s + clientesDo(t, d).length + checklistDo(t, d).length, 0);
    const nome = { segunda: "Segunda-feira", terca: "Terça-feira", quarta: "Quarta-feira", quinta: "Quinta-feira", sexta: "Sexta-feira" }[d.dia] || "Hoje";
    ui.dias.replaceChildren(...[el("strong", { text: nome }), el("span", { class: "muted", text: state.data.slice(8, 10) + "/" + state.data.slice(5, 7) }), total ? el("span", { class: "rotina-data-sep", text: "|", "aria-hidden": "true" }) : null, total ? el("small", { text: feitas + " de " + total + " concluídos" }) : null].filter(Boolean));
  }

  function checklist() {
    const todos = TURNOS_CHECK.flatMap(([t]) => checklistDo(t));
    const feitas = todos.filter((i) => i.status_tipo === "fechado").length;
    const grupos = TURNOS_CHECK.filter(([t]) => checklistDo(t).length > 0).map(([t, rotulo]) => el("div", { class: "rotina-grupo" }, [
      el("p", { class: "rotina-subtitulo", "data-turno": t, text: rotulo }),
      el("div", { class: "rotina-itens", role: "group", "aria-label": rotulo }, checklistDo(t).map((i) => {
        const feito = i.status_tipo === "fechado";
        // pedido do dono: tres pontinhos (editar o nome) logo antes do botao de concluir, a direita
        if (state.editando === i.id) {
          return el("div", { class: "rotina-item is-editando", "data-item": i.id }, [
            el("input", { type: "text", id: "rotinaEditar", class: "rotina-editar-campo", maxlength: "200", value: state.editTexto, "aria-label": "Nome do item", autocomplete: "off" }),
            iconeBotao("btn btn-primario btn-mini btn-certo", "salvar-nome", "Salvar nome", '<polyline points="20 6 9 17 4 12"/>'),
            el("button", { class: "btn btn-contorno btn-mini", type: "button", "data-check": "cancelar", text: "Cancelar" }),
          ]);
        }
        return el("div", { class: "rotina-item" + (feito ? " is-feito" : ""), "data-item": i.id }, [
          el("span", { class: "rotina-item-texto", text: i.titulo }),
          // tres pontinhos: abre o menu com Editar e Excluir (pedido do dono)
          el("span", { class: "rotina-mais-wrap" }, [
            iconeBotao("rotina-mais", "menu", "Mais opções", '<circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/>'),
            state.menuItem === i.id ? el("span", { class: "rotina-menu", role: "menu" }, [
              el("button", { class: "rotina-menu-op", type: "button", role: "menuitem", "data-check": "editar", text: "Editar" }),
              el("button", { class: "rotina-menu-op is-perigo", type: "button", role: "menuitem", "data-check": "excluir", text: "Excluir" }),
            ]) : null,
          ].filter(Boolean)),
          el("button", { class: "rotina-concluir", type: "button", "data-check": "concluir", "aria-pressed": feito ? "true" : "false", title: feito ? "Desfazer" : "Concluir", text: feito ? "✓ Concluído" : "Concluir" }),
        ]);
      })),
    ]));
    const hora = parseInt(raiz.dataset.hora || "9", 10);
    const adicionar = state.adicionando
      ? el("div", { class: "rotina-add-form" }, [
        el("input", { type: "text", id: "rotinaAddTexto", maxlength: "200", value: state.addTexto, placeholder: "Novo item do checklist", "aria-label": "Novo item do checklist", autocomplete: "off" }),
        el("select", { id: "rotinaAddTurno", "aria-label": "Turno" }, TURNOS_CHECK.map(([t, rotulo]) => el("option", { value: t, selected: (state.addTurno || (hora >= 13 ? "tarde" : "manha")) === t, text: rotulo }))),
        el("button", { class: "btn btn-primario btn-mini", type: "button", "data-check": "salvar-novo", text: "Salvar" }),
        el("button", { class: "btn btn-contorno btn-mini", type: "button", "data-check": "fechar-novo", text: "Cancelar" }),
      ])
      : el("button", { class: "btn btn-contorno btn-add", type: "button", "data-check": "abrir-novo", text: "Add +" });
    ui.checklist.replaceChildren(
      el("div", { class: "cartao-topo" }, [el("h3", { text: "Checklist do dia" }), el("span", { class: "contagem", text: feitas + " de " + todos.length })]),
      ...(grupos.length ? grupos : [el("p", { class: "muted", text: "Sem itens hoje." })]),
      adicionar,
    );
  }

  // botao so de icone (SVG fixo do codigo, sem conteudo do usuario)
  function iconeBotao(classe, acao, rotulo, desenho) {
    const b = el("button", { class: classe, type: "button", "data-check": acao, title: rotulo, "aria-label": rotulo });
    b.innerHTML = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + desenho + '</svg>';
    return b;
  }

  async function salvarNome(tarefa) {
    const titulo = state.editTexto.trim();
    if (!titulo) { toast("Escreva o nome do item.", true); return; }
    const anterior = tarefa.titulo;
    tarefa.titulo = titulo;
    state.editando = null;
    renderizar();
    state.versao++;
    try { await chamar("POST", "/api/tarefas/" + tarefa.id, { titulo }); toast("Nome atualizado."); }
    catch (e) { tarefa.titulo = anterior; toast(e.message, true); renderizar(); }
  }

  async function excluirItem(tarefa) {
    if (!(await confirmar('Excluir o item "' + tarefa.titulo + '" do checklist?'))) { renderizar(); return; }
    for (const d of Object.values(state.dados.semana)) for (const [t] of TURNOS_CHECK) {
      const lista = checklistDo(t, d);
      const pos = lista.indexOf(tarefa);
      if (pos >= 0) lista.splice(pos, 1);
    }
    renderizar();
    state.versao++;
    try { await chamar("POST", "/api/tarefas/" + tarefa.id + "/excluir", {}); toast("Item excluído."); }
    catch (e) { toast(e.message, true); carregar(false); }
  }

  // pedido do dono (23/11/2026): conclui uma tarefa de hoje do cliente em foco; a proxima da lista passa para "Em andamento"
  // e o cliente so fica concluido quando todas as tarefas de hoje terminam. Muda na hora e confirma no servidor.
  async function concluirTarefaCliente(c, id) {
    const guardada = state.tarefasCliente[c.cliente_id];
    const hoje = guardada ? guardada.lista.filter((x) => x.prazo === raiz.dataset.hoje) : [];
    const t = hoje.find((x) => x.id === id);
    if (!t) return;
    t.status_tipo = "fechado"; t.status_nome = STATUS.fechado;
    const proxima = hoje.find((x) => x.status_tipo !== "fechado");
    if (proxima && proxima.status_tipo === "aberto") { proxima.status_tipo = "andamento"; proxima.status_nome = STATUS.andamento; }
    if (proxima) {
      if (id === c.id) c.analise_feita = true;
      c.status_tipo = "andamento"; c.status_nome = STATUS.andamento;
    } else {
      c.status_tipo = "fechado"; c.status_nome = STATUS.fechado; c.analise_feita = false;
      state.foco = null;
    }
    renderizar();
    state.versao++;
    state.pendentes++;
    try {
      await chamar("POST", "/api/rotina/concluir", { tarefa_id: id });
      if (proxima && proxima.status_tipo === "andamento" && proxima.id !== c.id) await chamar("POST", "/api/rotina/iniciar", { tarefa_id: proxima.id });
      toast(proxima ? "Tarefa concluída. Próxima: " + proxima.titulo : "Cliente concluído.");
    } catch (e) { toast(e.message, true); }
    finally {
      state.pendentes--;
      guardada.velha = true;
      carregar(true);
    }
  }

  // detalhes da tarefa em pop-up no meio da tela; clicar fora (ou Esc) fecha e salva, escrito ou apagado (pedido do dono)
  function abrirDetalhe(c, id) {
    const t = ((state.tarefasCliente[c.cliente_id] || {}).lista || []).find((x) => x.id === id);
    if (!t) return;
    const campo = el("textarea", { id: "rotinaDetalhe", rows: "6", maxlength: "5000", placeholder: "Escreva os detalhes desta tarefa…", "aria-label": "Detalhes da tarefa", value: t.descricao || "" });
    const caixa = el("div", { class: "detalhe-caixa", role: "dialog", "aria-modal": "true", "aria-label": "Detalhes da tarefa" }, [
      el("strong", { class: "detalhe-titulo", text: t.titulo }),
      campo,
    ]);
    const fundo = el("div", { class: "detalhe-fundo" }, [caixa]);
    let fechado = false;
    const fechar = async () => {
      if (fechado) return;
      fechado = true;
      fundo.remove();
      document.removeEventListener("keydown", tecla, true);
      const novo = campo.value.trim();
      const antes = t.descricao || "";
      if (novo === antes) return;
      t.descricao = novo;
      renderizar();
      try { await chamar("POST", "/api/tarefas/" + id, { descricao: novo }); toast(novo ? "Detalhes salvos." : "Detalhes apagados."); }
      catch (e) { t.descricao = antes; toast(e.message, true); renderizar(); }
    };
    const tecla = (ev) => { if (ev.key === "Escape") { ev.preventDefault(); ev.stopPropagation(); fechar(); } };
    fundo.addEventListener("click", (ev) => { if (ev.target === fundo) fechar(); });
    document.addEventListener("keydown", tecla, true);
    document.body.append(fundo);
    campo.focus();
    campo.setSelectionRange(campo.value.length, campo.value.length);
  }

  async function salvarNovo(botao) {
    const titulo = state.addTexto.trim();
    const sel = $("rotinaAddTurno");
    const turno = sel ? sel.value : "manha";
    if (!titulo) { toast("Escreva o item antes de salvar.", true); return; }
    botao.disabled = true;
    try {
      await chamar("POST", "/api/tarefas", { titulo, lista_id: 1, prazo: raiz.dataset.hoje, turno, responsavel_id: state.dados.gestor_id });
      state.adicionando = false;
      state.addTexto = "";
      toast("Item adicionado ao checklist.");
      await carregar(false);
    } catch (e) { toast(e.message, true); botao.disabled = false; }
  }

  function foco() {
    const cliente = clientesDo(state.turno).find((c) => c.id === state.foco);
    if (!cliente) {
      const vazioDia = TURNOS_CHECK.every(([t]) => clientesDo(t).length + checklistDo(t).length === 0);
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
    const principal = el("div", { class: "rotina-foco-principal" }, [
      el("span", { class: "rotina-selo" + (tipo === "fechado" ? " is-ok" : ""), text: tipo === "fechado" ? "CONCLUÍDO" : "EM FOCO" }),
      // formato fixo pedido pelo dono: selo, nome, status (cada um em sua linha), nova tarefa, botoes
      el("h2", { class: "rotina-foco-nome", text: cliente.cliente_nome || cliente.titulo }),
      el("div", { class: "rotina-foco-status" }, [el("span", { class: "pilula is-" + tipo, text: STATUS[tipo] || cliente.status_nome })]),
      el("div", { class: "rotina-foco-nota" }, [
        el("label", { for: "rotinaNota", text: "Nova tarefa" }),
        el("div", { class: "nota-linha" }, [
          el("input", { type: "text", id: "rotinaNota", maxlength: "200", value: state.nota, placeholder: "Ex.: revisar os criativos da campanha", autocomplete: "off" }),
          iconeEnviar(),
        ]),
      ]),
      el("div", { class: "rotina-foco-acoes" }, [
        el("button", { class: "btn btn-contorno", type: "button", "data-acao": "abrir-cliente", text: "Ver informações" }),
        tipo === "aberto" ? el("button", { class: "btn btn-primario", type: "button", "data-acao": "iniciar", text: "Iniciar" }) : null,
        tipo === "fechado" ? el("button", { class: "btn btn-contorno", type: "button", "data-acao": "reabrir", text: "Reabrir" }) : null,
      ]),
    ].filter(Boolean));
    const lado = el("aside", { class: "rotina-foco-tarefas", "aria-label": "Tarefas do cliente" });
    ui.foco.replaceChildren(el("div", { class: "rotina-foco-corpo" }, [principal, lado]));
    tarefasDoCliente(cliente, lado);
  }

  // lado direito do foco: as tarefas vinculadas ao cliente em foco, com o status de cada uma
  async function tarefasDoCliente(cliente, lado) {
    const titulo = () => el("h3", { text: "Tarefas do cliente" });
    // pedido do dono (23/11/2026): so as tarefas de hoje; cada uma com um botao de certo que so libera depois de "Iniciar"
    const liberado = cliente.status_tipo !== "aberto";
    const desenhar = (todas) => {
      const lista = todas === null ? null : todas.filter((t) => t.prazo === raiz.dataset.hoje);
      const itens = lista === null ? [el("p", { class: "muted", text: "Carregando…" })]
        : lista.length === 0 ? [el("p", { class: "muted", text: "Nenhuma tarefa para hoje." })]
        : [el("ul", { class: "rotina-tarefas-lista" }, lista.map((t) => {
            // a tarefa em foco usa o status da tela (muda na hora ao iniciar/concluir)
            const st = t.id === cliente.id && !cliente.analise_feita ? { tipo: cliente.status_tipo, nome: STATUS[cliente.status_tipo] || cliente.status_nome } : { tipo: t.status_tipo, nome: t.status_nome };
            const feita = st.tipo === "fechado";
            const certo = el("button", { class: "rotina-tarefa-certo" + (feita ? " is-feita" : ""), type: "button", "data-acao": "concluir-tarefa", "data-tarefa": t.id,
              disabled: feita || !liberado, title: feita ? "Concluída" : liberado ? "Concluir tarefa" : "Clique em Iniciar para liberar", "aria-label": feita ? "Concluída" : "Concluir tarefa" });
            certo.innerHTML = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
            // botao de mais: detalhes da tarefa (guardados na descricao), pedido do dono 23/11/2026
            const mais = el("button", { class: "rotina-tarefa-mais", type: "button", "data-acao": "detalhe-tarefa", "data-tarefa": t.id, title: t.descricao ? "Editar detalhes" : "Adicionar detalhes", "aria-label": t.descricao ? "Editar detalhes" : "Adicionar detalhes", text: "+" });
            return el("li", { class: "rotina-tarefa" + (t.id === cliente.id ? " is-foco" : "") + (feita ? " is-feita" : "") }, [
              el("span", { class: "rotina-tarefa-texto" }, [
                el("span", { text: t.titulo }),
                t.prazo ? el("small", { class: "muted", text: t.prazo.slice(8, 10) + "/" + t.prazo.slice(5, 7) }) : null,
                t.descricao ? el("small", { class: "rotina-tarefa-detalhe", text: t.descricao }) : null,
              ].filter(Boolean)),
              el("span", { class: "rotina-tarefa-fim" }, [mais, el("span", { class: "pilula is-" + st.tipo, text: st.nome }), certo]),
            ]);
          }))];
      const antiga = lado.querySelector(".rotina-tarefas-lista");
      const rolagem = antiga ? antiga.scrollTop : null;
      lado.replaceChildren(titulo(), ...itens);
      // mostra no maximo 3 tarefas; as demais aparecem rolando a lista (pedido do dono)
      const ul = lado.querySelector(".rotina-tarefas-lista");
      if (!ul || ul.children.length <= 3) return;
      ul.style.maxHeight = (ul.children[3].offsetTop - ul.children[0].offsetTop) + "px";
      const emFoco = ul.querySelector(".is-foco");
      ul.scrollTop = rolagem !== null ? rolagem : (emFoco ? emFoco.offsetTop - ul.children[0].offsetTop : 0);
    };
    if (!cliente.cliente_id) return desenhar([]);
    // mostra o que ja tem na hora e busca de novo quando a Rotina recarregou (sem piscar "Carregando…")
    const id = cliente.cliente_id;
    const guardada = state.tarefasCliente[id];
    desenhar(guardada ? guardada.lista : null);
    if (guardada && !guardada.velha) return;
    try {
      state.tarefasCliente[id] = { lista: (await chamar("GET", "/api/tarefas?cliente_id=" + id)).tarefas || [], velha: false };
    } catch (e) {
      if (!guardada && lado.isConnected) lado.replaceChildren(titulo(), el("p", { class: "muted", text: "Não foi possível carregar as tarefas." }));
      return;
    }
    if (lado.isConnected) desenhar(state.tarefasCliente[id].lista);
  }

  function clientes() {
    const partes = [el("h2", { text: "Clientes do dia" })];
    TURNOS.forEach(([t, rotulo]) => {
      const lista = clientesDo(t);
      partes.push(el("div", { class: "rotina-grupo" }, [
        el("div", { class: "rotina-grupo-topo", "data-turno": t, text: rotulo }),
        ...(lista.length ? lista.map((c) => el("button", { class: "rotina-cliente" + (c.id === state.foco && t === state.turno ? " is-foco" : "") + (c.status_tipo === "fechado" ? " is-concluido" : ""), type: "button", "data-cliente": c.id, "data-turno": t }, [
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
    // quem estava sendo digitado continua com o cursor depois de redesenhar (a Rotina recarrega a cada minuto)
    const ativo = document.activeElement && ["rotinaNota", "rotinaEditar", "rotinaAddTexto"].includes(document.activeElement.id) ? document.activeElement.id : null;
    topo();
    checklist();
    foco();
    clientes();
    // menu dos tres pontinhos em lista: se nao couber embaixo dentro do bloco, abre para cima
    const menu = ui.checklist.querySelector(".rotina-menu");
    if (menu && menu.getBoundingClientRect().bottom > ui.checklist.getBoundingClientRect().bottom - 4) menu.classList.add("para-cima");
    if (ativo) { const campo = $(ativo); if (campo) { campo.focus(); campo.setSelectionRange(campo.value.length, campo.value.length); } }
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
    const check = event.target.closest("[data-check]");
    // clique fora do menu dos tres pontinhos fecha o menu
    if (state.menuItem !== null && (!check || !["menu", "editar", "excluir"].includes(check.dataset.check))) { state.menuItem = null; renderizar(); }
    if (check) {
      const tipo = check.dataset.check;
      if (tipo === "abrir-novo") { state.adicionando = true; renderizar(); const c = $("rotinaAddTexto"); if (c) c.focus(); return; }
      if (tipo === "fechar-novo") { state.adicionando = false; state.addTexto = ""; return renderizar(); }
      if (tipo === "salvar-novo") return salvarNovo(check);
      const item = check.closest(".rotina-item");
      const tarefa = item && tarefaPorId(parseInt(item.dataset.item, 10));
      if (!tarefa) return;
      if (tipo === "menu") { state.menuItem = state.menuItem === tarefa.id ? null : tarefa.id; return renderizar(); }
      state.menuItem = null;
      if (tipo === "excluir") return excluirItem(tarefa);
      if (tipo === "editar") { state.editando = tarefa.id; state.editTexto = tarefa.titulo; renderizar(); const c = $("rotinaEditar"); if (c) { c.focus(); c.select(); } return; }
      if (tipo === "cancelar") { state.editando = null; return renderizar(); }
      if (tipo === "salvar-nome") return salvarNome(tarefa);
      if (tipo === "concluir") return acao(tarefa, tarefa.status_tipo === "fechado" ? "aberto" : "fechado", tarefa.status_tipo === "fechado" ? "reabrir" : "concluir");
      return;
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
    if (botao.dataset.acao === "nova-tarefa") return criarTarefa(c, botao);
    if (botao.dataset.acao === "concluir") { acao(c, "fechado", "concluir"); toast("Análise registrada."); }
    if (botao.dataset.acao === "detalhe-tarefa") return abrirDetalhe(c, parseInt(botao.dataset.tarefa, 10));
    if (botao.dataset.acao === "concluir-tarefa") return concluirTarefaCliente(c, parseInt(botao.dataset.tarefa, 10));
    if (botao.dataset.acao === "iniciar") acao(c, "andamento", "iniciar");
    if (botao.dataset.acao === "reabrir") acao(c, "aberto", "reabrir");
  });

  raiz.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && state.menuItem !== null) { state.menuItem = null; renderizar(); return; }
    if (event.target.id === "rotinaEditar" || event.target.id === "rotinaAddTexto") {
      const editar = event.target.id === "rotinaEditar";
      if (event.key === "Escape") { if (editar) state.editando = null; else { state.adicionando = false; state.addTexto = ""; } renderizar(); return; }
      if (event.key !== "Enter") return;
      event.preventDefault();
      if (editar) { const t = tarefaPorId(state.editando); if (t) salvarNome(t); } else { const b = raiz.querySelector('[data-check="salvar-novo"]'); if (b) salvarNovo(b); }
      return;
    }
    if (event.key !== "Enter" || event.target.id !== "rotinaNota") return;
    event.preventDefault();
    const c = clientesDo(state.turno).find((x) => x.id === state.foco);
    const botao = raiz.querySelector('[data-acao="nova-tarefa"]');
    if (c && botao) criarTarefa(c, botao);
  });
  raiz.addEventListener("input", (event) => {
    if (event.target.id === "rotinaNota") state.nota = event.target.value;
    if (event.target.id === "rotinaEditar") state.editTexto = event.target.value;
    if (event.target.id === "rotinaAddTexto") state.addTexto = event.target.value;
  });
  raiz.addEventListener("change", (event) => { if (event.target.id === "rotinaAddTurno") state.addTurno = event.target.value; });
  if (ui.atualizar) ui.atualizar.addEventListener("click", () => carregar(false));
  if (ui.gestor) ui.gestor.addEventListener("change", () => { state.dados = null; state.foco = null; carregar(false); });

  carregar(false);
  setInterval(() => { if (!document.hidden) carregar(true); }, 60000);
})();
