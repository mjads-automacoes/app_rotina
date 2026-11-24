// Entregavel do cliente (ficha): etiqueta colorida escolhida numa lista, como no ClickUp. Pedido do dono, 24/11/2026.
// Escolher salva com a ficha (campo escondido "entregaveis"). O administrador edita a lista: nome, cor, ordem
// (arrastando pelos pontinhos), cria e exclui; cada mudanca vai para POST /api/entregaveis e vale para todos os clientes.
(function () {
  "use strict";

  const caixa = document.querySelector("[data-entregavel]");
  if (!caixa) return;
  const { el, toast, chamar, confirmar } = window.Rotina;
  const campo = caixa.querySelector("input[type=hidden]");
  const botao = caixa.querySelector("[data-entregavel-abrir]");
  const podeEditar = caixa.dataset.editar === "1";
  const CORES = (caixa.dataset.cores || "").split(",").filter(Boolean);
  let opcoes = [];
  try { opcoes = JSON.parse(caixa.dataset.opcoes || "[]"); } catch (e) { opcoes = []; }
  let painel = null;
  let editando = null; // id da etiqueta em edicao ("novo" para uma nova)
  let arrastado = null;

  // texto escuro em cor clara (ex.: amarelo), branco nas demais
  const textoPara = (hex) => {
    const n = parseInt(hex.slice(1), 16);
    const l = (0.299 * ((n >> 16) & 255) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255;
    return l > 0.62 ? "#1b1d21" : "#fff";
  };
  const pilula = (o) => {
    const p = el("span", { class: "etiqueta", text: o.nome });
    p.style.background = o.cor;
    p.style.color = textoPara(o.cor);
    return p;
  };
  const icone = (desenho) => { const s = el("span", { class: "etiqueta-ic", "aria-hidden": "true" }); s.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor">' + desenho + "</svg>"; return s; };
  const PONTOS = '<circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/>';
  const ALCA = '<circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/>';

  function mostrarAtual() {
    const atual = opcoes.find((o) => o.id === campo.value);
    const alvo = botao.querySelector("[data-entregavel-atual]");
    alvo.replaceChildren(atual ? pilula(atual) : el("span", { class: "muted", text: "—" }));
  }

  function escolher(id) {
    campo.value = id;
    campo.dispatchEvent(new Event("input", { bubbles: true }));
    campo.dispatchEvent(new Event("change", { bubbles: true }));
    mostrarAtual();
    fechar();
    botao.focus();
  }

  async function salvarLista(nova, aviso) {
    const antes = opcoes;
    opcoes = nova;
    desenhar();
    try {
      opcoes = (await chamar("POST", "/api/entregaveis", { opcoes: nova })).entregaveis;
      if (!opcoes.some((o) => o.id === campo.value)) campo.value = "";
      if (aviso) toast(aviso);
    } catch (e) { opcoes = antes; toast(e.message, true); }
    mostrarAtual();
    desenhar();
  }

  function linhaEdicao(o) {
    const nome = el("input", { type: "text", class: "etiqueta-nome", maxlength: "60", value: o.nome, "aria-label": "Nome do entregável", autocomplete: "off" });
    let cor = o.cor;
    const cores = el("div", { class: "etiqueta-cores" }, CORES.map((c) => {
      const b = el("button", { type: "button", class: "etiqueta-cor" + (c === cor ? " is-escolhida" : ""), "aria-label": "Cor " + c, title: c });
      b.style.background = c;
      b.addEventListener("click", () => { cor = c; cores.querySelectorAll(".etiqueta-cor").forEach((x) => x.classList.toggle("is-escolhida", x === b)); });
      return b;
    }));
    const concluir = () => {
      const texto = nome.value.trim();
      if (!texto) { toast("Escreva o nome do entregável.", true); return; }
      editando = null;
      if (o.id === "novo") salvarLista([...opcoes, { nome: texto, cor }], "Entregável criado.");
      else salvarLista(opcoes.map((x) => (x.id === o.id ? { id: x.id, nome: texto, cor } : x)), "Entregável atualizado.");
    };
    nome.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter") { ev.preventDefault(); concluir(); }
      if (ev.key === "Escape") { ev.preventDefault(); ev.stopPropagation(); editando = null; desenhar(); }
    });
    const pronto = el("button", { type: "button", class: "btn btn-primario btn-mini", text: "Salvar" });
    pronto.addEventListener("click", concluir);
    const partes = [nome, cores, el("div", { class: "etiqueta-acoes" }, [pronto])];
    if (o.id !== "novo") {
      const excluir = el("button", { type: "button", class: "btn btn-contorno btn-mini etiqueta-excluir", text: "Excluir" });
      excluir.addEventListener("click", async () => {
        if (!(await confirmar('Excluir o entregável "' + o.nome + '"? Os clientes com ele ficam sem entregável.'))) return;
        editando = null;
        salvarLista(opcoes.filter((x) => x.id !== o.id), "Entregável excluído.");
      });
      partes[2].prepend(excluir);
    }
    const linha = el("div", { class: "etiqueta-edicao" }, partes);
    setTimeout(() => { nome.focus(); nome.select(); }, 0);
    return linha;
  }

  function desenhar() {
    if (!painel) return;
    const itens = [el("button", { type: "button", class: "etiqueta-linha etiqueta-nenhuma" + (campo.value === "" ? " is-escolhida" : ""), "data-escolher": "", text: "—" })];
    opcoes.forEach((o) => {
      if (editando === o.id) { itens.push(linhaEdicao(o)); return; }
      const linha = el("div", { class: "etiqueta-linha" + (o.id === campo.value ? " is-escolhida" : ""), "data-id": o.id, draggable: podeEditar ? "true" : null }, [
        podeEditar ? el("span", { class: "etiqueta-alca", title: "Arraste para mudar a ordem" }, [icone(ALCA)]) : null,
        el("button", { type: "button", class: "etiqueta-escolher", "data-escolher": o.id }, [pilula(o)]),
        podeEditar ? el("button", { type: "button", class: "etiqueta-mais", "data-editar-etiqueta": o.id, title: "Editar", "aria-label": "Editar " + o.nome }, [icone(PONTOS)]) : null,
      ].filter(Boolean));
      itens.push(linha);
    });
    if (podeEditar) itens.push(editando === "novo" ? linhaEdicao({ id: "novo", nome: "", cor: CORES[0] || "#2ecc71" })
      : el("button", { type: "button", class: "etiqueta-nova", "data-nova-etiqueta": "", text: "+ Novo entregável" }));
    painel.replaceChildren(...itens);
  }

  function abrir() {
    painel = el("div", { class: "etiquetas-painel", role: "listbox" });
    // digitar aqui nao dispara o salvamento automatico da ficha
    ["input", "change"].forEach((t) => painel.addEventListener(t, (ev) => ev.stopPropagation()));
    painel.addEventListener("click", (ev) => {
      ev.stopPropagation(); // o painel se redesenha: o clique nao pode chegar ao "clicou fora, fecha"
      const esc = ev.target.closest("[data-escolher]");
      if (esc) return escolher(esc.dataset.escolher);
      const ed = ev.target.closest("[data-editar-etiqueta]");
      if (ed) { editando = ed.dataset.editarEtiqueta; return desenhar(); }
      if (ev.target.closest("[data-nova-etiqueta]")) { editando = "novo"; return desenhar(); }
    });
    // reordenar arrastando
    painel.addEventListener("dragstart", (ev) => { const l = ev.target.closest(".etiqueta-linha[data-id]"); if (!l) return; arrastado = l; l.classList.add("arrastando"); ev.dataTransfer.effectAllowed = "move"; ev.dataTransfer.setData("text/plain", l.dataset.id); });
    painel.addEventListener("dragover", (ev) => {
      const alvo = ev.target.closest(".etiqueta-linha[data-id]");
      if (!arrastado || !alvo || alvo === arrastado) return;
      ev.preventDefault();
      const r = alvo.getBoundingClientRect();
      alvo.parentNode.insertBefore(arrastado, ev.clientY > r.top + r.height / 2 ? alvo.nextSibling : alvo);
    });
    painel.addEventListener("drop", (ev) => ev.preventDefault());
    painel.addEventListener("dragend", () => {
      if (!arrastado) return;
      arrastado.classList.remove("arrastando");
      arrastado = null;
      const ordem = [...painel.querySelectorAll(".etiqueta-linha[data-id]")].map((l) => l.dataset.id);
      if (ordem.join() !== opcoes.map((o) => o.id).join()) salvarLista(ordem.map((id) => opcoes.find((o) => o.id === id)), "Ordem salva.");
    });
    caixa.append(painel);
    botao.setAttribute("aria-expanded", "true");
    desenhar();
  }

  function fechar() { if (painel) { painel.remove(); painel = null; editando = null; botao.setAttribute("aria-expanded", "false"); } }

  botao.addEventListener("click", () => (painel ? fechar() : abrir()));
  document.addEventListener("click", (ev) => { if (painel && !caixa.contains(ev.target) && !ev.target.closest(".confirmar-fundo, .emoji-painel, .emoji-botao")) fechar(); });
  document.addEventListener("keydown", (ev) => { if (ev.key === "Escape" && painel && !editando) fechar(); });
  window.Rotina.entregaveis = { escolher, salvarLista, opcoes: () => opcoes };
  mostrarAtual();
})();
