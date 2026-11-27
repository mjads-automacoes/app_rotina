// Aba Agentes, novo pedido (27/11/2026): o formulario e montado a partir da especificacao do dono
// (src/agentes-formularios.json), servida por GET /api/agentes/esquema. Cabecalho comum + campos do tipo de pedido.
// Campos com "da ficha" chegam preenchidos pelo cliente escolhido. Anexos: por enquanto so link do Drive.
(function () {
  "use strict";

  const raiz = document.getElementById("pedidoNovo");
  if (!raiz) return;
  const { el, toast, chamar } = window.Rotina;

  const PALAVRAS = { orcamento: "Orçamento", publico: "Público", criativo: "Criativo", oferta: "Oferta", nenhuma: "Nenhuma", arquivo: "Arquivo", texto: "Texto", link: "Link",
    "3_variacoes": "3 variações para teste", "1": "1 arte", carrossel: "Carrossel", "7d": "Últimos 7 dias", "14d": "Últimos 14 dias", "30d": "Últimos 30 dias" };
  const NENHUM = { sem_referencia: "Sem referência", nao_temos: "Não temos", nao_se_aplica: "Não se aplica", nada: "Nada", criar_com_base_na_ficha: "Criar com base na ficha",
    escritorio_cria: "O escritório cria", a_escolha: "À escolha", usar_da_ficha: "Usar da ficha", sem_oferta: "Sem oferta" };
  // campo "ficha.x" da especificacao -> coluna da ficha na Rotina
  const FICHA = { meta_ad_account_id: "meta_conta_id", paginas: "meta_paginas", google_ads_id: "google_ads_id", whatsapp_comercial: "whatsapp_comercial", cidade: "cidade",
    endereco: "endereco", logo: "logo", orcamento_combinado: "orcamento", dominio: "dominio", cores: "cores" };

  let dados = null; // {esquema, clientes, usuarios, eu, hoje}
  let campos = {}; // id -> {def, ler(), caixa, aplicarFicha?}
  const humano = (v) => PALAVRAS[v] || String(v).replace(/_/g, " ").replace(/^./, (c) => c.toUpperCase());
  const opcoesDe = (def) => (def.options || []).map((o) => (typeof o === "object" ? { value: String(o.value), label: o.label } : { value: String(o), label: humano(o) }));
  const cliente = () => dados.clientes.find((c) => String(c.id) === String(valor("cliente_id"))) || null;
  const valor = (id) => (campos[id] ? campos[id].ler() : undefined);

  function vazio(v) {
    if (v == null) return true;
    if (typeof v === "boolean" || typeof v === "number") return false;
    if (Array.isArray(v)) return v.every(vazio);
    if (typeof v === "object") return !(v.nenhum || v.sem_oferta) && Object.values(v).every(vazio);
    return String(v).trim() === "";
  }
  function obrigatorio(def) {
    if (def.required_if) return (def.required_if.in || []).includes(valor(def.required_if.field));
    return !!def.required && !def.soft_required;
  }

  // ---------- um campo ----------
  function campo(def) {
    const tipo = def.type;
    let entrada;
    let ler;
    const input = (attrs) => el("input", Object.assign({ type: "text", autocomplete: "off" }, attrs));
    const sel = (lista, extra) => el("select", extra || {}, [el("option", { value: "", text: "Escolha…" }), ...lista.map((o) => el("option", { value: o.value, text: o.label }))]);

    if (["text", "url", "domain", "phone", "currency_brl", "text_or_link", "phone_or_url", "upload_or_drive", "link_or_upload", "upload", "url_or_request_ref", "request_ref_or_upload"].includes(tipo)) {
      const ehLink = /upload|drive|link|url|request_ref/.test(tipo);
      const ph = tipo === "currency_brl" ? "R$ 0,00" : tipo === "phone" ? "(18) 99999-0000" : tipo === "domain" ? "site.com.br" : ehLink ? "Cole o link do Drive ou da página" : "";
      entrada = input({ placeholder: ph, inputmode: tipo === "phone" ? "tel" : null });
      ler = () => entrada.value.trim();
    } else if (tipo === "textarea" || tipo === "qa_list" || tipo === "textarea_with_upload") {
      entrada = el("textarea", { rows: tipo === "qa_list" ? "4" : "3", placeholder: tipo === "qa_list" ? "Pergunta? Resposta (uma por linha)" : tipo === "textarea_with_upload" ? "Escreva e, se tiver, cole o link do Drive" : "" });
      ler = () => entrada.value.trim();
    } else if (tipo === "list") {
      entrada = el("textarea", { rows: "3", placeholder: "Um item por linha" });
      ler = () => entrada.value.split("\n").map((x) => x.trim()).filter(Boolean);
    } else if (tipo === "select" || tipo === "user") {
      const lista = tipo === "user" ? dados.usuarios.map((u) => ({ value: String(u.id), label: u.nome })) : opcoesDe(def);
      entrada = sel(lista);
      if (tipo === "user") entrada.value = String(dados.eu.id);
      if (def.default != null) entrada.value = String(def.default);
      ler = () => entrada.value;
    } else if (tipo === "boolean") {
      entrada = sel([{ value: "1", label: "Sim" }, { value: "0", label: "Não" }]);
      entrada.value = def.default === false ? "0" : "1";
      ler = () => (entrada.value === "" ? null : entrada.value === "1");
    } else if (tipo === "date" || tipo === "datetime") {
      entrada = input({ type: tipo === "date" ? "date" : "datetime-local", min: dados.hoje + (tipo === "date" ? "" : "T00:00") });
      ler = () => entrada.value;
    } else if (tipo === "multiselect" || tipo === "multiselect_or_text") {
      const lista = def.options ? opcoesDe(def) : [];
      if (def.allow_all) lista.unshift({ value: "todas", label: "Todas" });
      const caixas = lista.map((o) => el("label", { class: "check" }, [el("input", { type: "checkbox", value: o.value }), " " + o.label]));
      const extra = def.detail_text || tipo === "multiselect_or_text" ? input({ placeholder: tipo === "multiselect_or_text" ? "Ou escreva quais" : "Detalhe (opcional)" }) : null;
      entrada = el("div", { class: "pedido-opcoes" }, [...caixas, extra].filter(Boolean));
      ler = () => ({ opcoes: caixas.map((c) => c.querySelector("input")).filter((i) => i.checked).map((i) => i.value), texto: extra ? extra.value.trim() : "" });
    } else if (tipo === "select_with_text" || tipo === "select_with_number") {
      const s = sel(opcoesDe(def));
      const extra = tipo === "select_with_text" ? input({ placeholder: "Detalhe / formato" }) : input({ type: "number", min: "2", max: "10", placeholder: "Telas", hidden: true });
      if (tipo === "select_with_number") s.addEventListener("change", () => { extra.hidden = !(def.number_if || {})[s.value]; });
      entrada = el("div", { class: "pedido-linha-dupla" }, [s, extra]);
      ler = () => ({ opcao: s.value, [tipo === "select_with_text" ? "texto" : "numero"]: extra.hidden ? "" : extra.value.trim() });
    } else if (tipo === "period") {
      const s = sel([...(def.presets || []).map((p) => ({ value: p, label: humano(p) })), ...(def.allow_custom_range ? [{ value: "intervalo", label: "Escolher datas" }] : [])]);
      const de = input({ type: "date", hidden: true });
      const ate = input({ type: "date", hidden: true });
      s.addEventListener("change", () => { de.hidden = ate.hidden = s.value !== "intervalo"; });
      entrada = el("div", { class: "pedido-linha-dupla" }, [s, de, ate]);
      ler = () => (s.value === "intervalo" ? { de: de.value, ate: ate.value } : s.value);
    } else if (tipo === "city_radius") {
      const cidade = input({ placeholder: "Cidade" });
      const km = input({ type: "number", min: "0", placeholder: "Raio (km)" });
      entrada = el("div", { class: "pedido-linha-dupla" }, [cidade, km]);
      ler = () => ({ cidade: cidade.value.trim(), km: km.value.trim() });
      entrada.preencher = (v) => { if (!cidade.value) cidade.value = v; };
    } else if (tipo === "offer") {
      const texto = input({ placeholder: "Oferta real (ex.: revisão por R$ 99)" });
      const validade = input({ type: "date", min: dados.hoje, title: "Validade" });
      entrada = el("div", { class: "pedido-linha-dupla" }, [texto, validade]);
      ler = () => ({ texto: texto.value.trim(), validade: validade.value });
      def = Object.assign({}, def, { allow_none_option: "sem_oferta" });
    } else if (tipo === "repeater") {
      const blocos = el("div", { class: "pedido-repeticao" });
      const novo = () => {
        const partes = (def.item_fields || []).map((f) => {
          const c = f.type === "textarea" ? el("textarea", { rows: "2", "data-item": f.id }) : el("input", { type: "text", "data-item": f.id, placeholder: /print/i.test(f.label) ? "Link do print (opcional)" : "" });
          return el("label", {}, [f.label + (f.required ? " *" : ""), c]);
        });
        blocos.append(el("div", { class: "pedido-bloco" }, partes));
      };
      novo();
      const mais = el("button", { type: "button", class: "btn btn-contorno btn-mini", text: "+ adicionar ajuste" });
      mais.addEventListener("click", novo);
      entrada = el("div", {}, [blocos, mais]);
      ler = () => [...blocos.children].map((b) => Object.fromEntries([...b.querySelectorAll("[data-item]")].map((i) => [i.dataset.item, i.value.trim()]))).filter((x) => !vazio(x));
    } else {
      entrada = input({});
      ler = () => entrada.value.trim();
    }

    // "nao se aplica", "sem oferta", "sem referencia"...: resposta intencional
    const chaveNenhum = def.allow_none_option || (def.validation || {}).allow_none_option;
    let nenhum = null;
    if (chaveNenhum) {
      nenhum = el("input", { type: "checkbox" });
      nenhum.addEventListener("change", () => { entrada.classList.toggle("is-desligado", nenhum.checked); entrada.querySelectorAll && [entrada, ...entrada.querySelectorAll("input, select, textarea")].forEach((x) => { if (x.disabled !== undefined) x.disabled = nenhum.checked; }); });
    }
    const lerFinal = () => (nenhum && nenhum.checked ? (chaveNenhum === "sem_oferta" ? { sem_oferta: true } : { nenhum: chaveNenhum }) : ler());
    let confirmar = null;
    if (def.confirm_checkbox) confirmar = el("label", { class: "check pedido-confirma" }, [el("input", { type: "checkbox", "data-confirmar-campo": "" }), " " + def.confirm_checkbox]);

    const selo = el("span", { class: "selo-ficha", text: "da ficha", hidden: true });
    const rotulo = el("span", { class: "pedido-rotulo" }, [def.label, el("b", { class: "pedido-obrig", text: " *" }), selo]);
    const caixa = el("div", { class: "pedido-campo", "data-campo": def.id }, [
      rotulo, entrada,
      nenhum ? el("label", { class: "check pedido-nenhum" }, [nenhum, " " + (NENHUM[chaveNenhum] || humano(chaveNenhum))]) : null,
      confirmar,
      def.help ? el("small", { class: "muted", text: def.help }) : null,
    ].filter(Boolean));

    // pre-preenche com a ficha do cliente (so se estiver vazio), com o selo "da ficha"
    const aplicarFicha = () => {
      const c = cliente();
      let chave = def.prefill || def.prefill_logo;
      if (def.prefill_by) chave = valor(def.prefill_by) === "google" ? "ficha.google_ads_id" : "ficha.meta_ad_account_id";
      if (!c || !chave) return;
      const v = c[FICHA[String(chave).replace("ficha.", "")] || String(chave).replace("ficha.", "")];
      if (!v) return;
      if (entrada.preencher) entrada.preencher(v);
      else if ((entrada.tagName === "INPUT" || entrada.tagName === "TEXTAREA") && !entrada.value) entrada.value = v;
      else return;
      selo.hidden = false;
    };
    campos[def.id] = { def, ler: lerFinal, caixa, aplicarFicha, confirmar };
    return caixa;
  }

  // atualiza obrigatorios (required_if) e opcoes que dependem de outro campo
  function atualizarRegras() {
    Object.values(campos).forEach(({ def, caixa }) => {
      caixa.querySelector(".pedido-obrig").hidden = !obrigatorio(def);
      if (def.required_if && !def.required) caixa.hidden = !obrigatorio(def);
      const s = caixa.querySelector("select");
      if (!s) return;
      Object.entries(def.option_visible_if || {}).forEach(([op, cond]) => { const o = s.querySelector('option[value="' + op + '"]'); if (o) o.hidden = !(cond.in || []).includes(valor(cond.field)); });
      Object.entries(def.option_disabled_if || {}).forEach(([op, cond]) => { const o = s.querySelector('option[value="' + op + '"]'); if (o) o.disabled = (cond.in || []).includes(valor(cond.field)); });
    });
  }

  // ---------- formulario ----------
  function desenhar(manter) {
    const esq = dados.esquema;
    const agente = manter.agente || "";
    const tipo = manter.tipo || "";
    campos = {};
    const pedAgente = el("select", { id: "pedAgente" }, [el("option", { value: "", text: "Escolha o agente…" }), ...Object.entries(esq.agents).map(([k, a]) => el("option", { value: k, text: a.label }))]);
    pedAgente.value = agente;
    const formas = agente ? esq.agents[agente].forms : {};
    const pedTipo = el("select", { id: "pedTipo", disabled: !agente }, [el("option", { value: "", text: agente ? "Escolha o tipo…" : "Escolha o agente primeiro" }), ...Object.entries(formas).map(([k, f]) => el("option", { value: k, text: f.label }))]);
    pedTipo.value = tipo;
    pedAgente.addEventListener("change", () => desenhar({ agente: pedAgente.value, cliente: valor("cliente_id") }));
    pedTipo.addEventListener("change", () => desenhar({ agente, tipo: pedTipo.value, cliente: valor("cliente_id") }));

    // cabecalho comum (o aprovador e sempre quem pede: decisao do dono)
    const comuns = esq.common_fields.filter((f) => !["agente", "tipo", "solicitante_id", "aprovador_id"].includes(f.id)).map((f) => {
      if (f.type === "client_lookup") {
        const s = el("select", {}, [el("option", { value: "", text: "Escolha o cliente…" }), ...dados.clientes.map((c) => el("option", { value: String(c.id), text: c.nome }))]);
        s.value = String(manter.cliente || raiz.dataset.cliente || "");
        campos[f.id] = { def: f, ler: () => s.value, caixa: null };
        const caixa = el("div", { class: "pedido-campo", "data-campo": f.id }, [el("span", { class: "pedido-rotulo" }, [f.label, el("b", { class: "pedido-obrig", text: " *" })]), s, el("small", { class: "muted", text: "Os campos com o selo \"da ficha\" chegam preenchidos." })]);
        campos[f.id].caixa = caixa;
        s.addEventListener("change", () => Object.values(campos).forEach((c) => c.aplicarFicha && c.aplicarFicha()));
        return caixa;
      }
      return campo(f);
    });
    const especificos = agente && tipo ? formas[tipo].fields.map(campo) : [];
    const aviso = agente && esq.agents[agente].notice ? el("div", { class: "pedido-aviso", text: esq.agents[agente].notice }) : null;

    const enviar = el("button", { type: "submit", class: "btn btn-primario", text: "Enviar pedido", disabled: !(agente && tipo) });
    const form = el("form", { class: "cartao pedido-form", novalidate: "" }, [
      el("div", { class: "pedido-regras" }, [el("strong", { text: "Regras de ouro" }), el("span", { text: "Nada vai ao cliente ou é publicado sem a equipe · oferta, preço, prazo e depoimento precisam ser reais · IA nunca imita fachada, equipe ou trabalho real · um pedido por formulário, de um cliente só." })]),
      el("div", { class: "pedido-grade" }, [
        el("div", { class: "pedido-campo" }, [el("span", { class: "pedido-rotulo" }, ["Agente", el("b", { class: "pedido-obrig", text: " *" })]), pedAgente]),
        el("div", { class: "pedido-campo" }, [el("span", { class: "pedido-rotulo" }, ["Tipo de pedido", el("b", { class: "pedido-obrig", text: " *" })]), pedTipo]),
        ...comuns,
        el("div", { class: "pedido-campo" }, [el("span", { class: "pedido-rotulo", text: "Solicitante e aprovador" }), el("span", { class: "pedido-eu", text: dados.eu.nome + " (você aprova a entrega)" })]),
      ]),
      aviso,
      especificos.length ? el("h2", { class: "pedido-secao", text: formas[tipo].label }) : null,
      el("div", { class: "pedido-grade" }, especificos),
      el("div", { class: "pedido-rodape" }, [el("a", { class: "btn btn-contorno", href: "/agentes", text: "Voltar" }), enviar]),
    ].filter(Boolean));
    form.addEventListener("input", atualizarRegras);
    form.addEventListener("change", atualizarRegras);
    form.addEventListener("submit", (ev) => { ev.preventDefault(); salvar(agente, tipo, enviar); });
    raiz.replaceChildren(form);
    Object.values(campos).forEach((c) => c.aplicarFicha && c.aplicarFicha());
    atualizarRegras();
  }

  async function salvar(agente, tipo, botao) {
    raiz.querySelectorAll(".pedido-campo.is-erro").forEach((c) => c.classList.remove("is-erro"));
    const faltando = Object.values(campos).find(({ def, ler, confirmar }) => (obrigatorio(def) && vazio(ler())) || (confirmar && !vazio(ler()) && !(ler() || {}).nenhum && !confirmar.querySelector("input").checked));
    if (faltando) {
      faltando.caixa.classList.add("is-erro");
      faltando.caixa.scrollIntoView({ block: "center", behavior: "smooth" });
      toast("Preencha: " + faltando.def.label, true);
      return;
    }
    const comuns = ["cliente_id", "prioridade", "prioridade_motivo", "prazo"];
    const corpo = { agente, tipo, cliente_id: Number(valor("cliente_id")), prioridade: valor("prioridade") || "normal", prioridade_motivo: valor("prioridade_motivo") || "", prazo: valor("prazo"), campos: {} };
    Object.entries(campos).forEach(([id, c]) => { if (!comuns.includes(id)) corpo.campos[id] = c.ler(); });
    botao.disabled = true;
    try {
      const r = await chamar("POST", "/api/pedidos", corpo);
      window.location.href = "/agentes/" + r.id;
    } catch (e) { toast(e.message, true); botao.disabled = false; }
  }

  chamar("GET", "/api/agentes/esquema").then((d) => { dados = d; desenhar({}); }).catch((e) => { raiz.replaceChildren(el("p", { class: "aviso aviso-erro", text: e.message })); });
})();
