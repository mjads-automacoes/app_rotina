// Clientes em Quadro (kanban: Novo, Ativo, Alerta, Churn) ou Lista. Arrastar um cartao muda a situacao do cliente.
// Pedido do dono, 24/11/2026. A situacao vai para o servidor pelo mesmo salvamento da ficha (POST /api/clientes/{id}).
(function () {
  "use strict";

  const raiz = document.getElementById("clientesLista");
  if (!raiz) return;
  const { toast, chamar } = window.Rotina;
  const NOMES = { novo: "Novo", ativo: "Ativo", alerta: "Alerta", churn: "Churn" };
  const quadro = document.getElementById("clientesQuadro");
  const tabela = document.getElementById("clientesTabela");
  const botoes = document.querySelectorAll("[data-vista-btn]");

  // ---- Quadro x Lista (lembra a escolha neste navegador)
  const mostrar = (vista) => {
    quadro.hidden = vista !== "quadro";
    tabela.hidden = vista !== "lista";
    botoes.forEach((b) => b.classList.toggle("is-ativo", b.dataset.vistaBtn === vista));
    try { localStorage.setItem("rotina_clientes_vista", vista); } catch (e) { /* sem armazenamento */ }
  };
  botoes.forEach((b) => b.addEventListener("click", () => mostrar(b.dataset.vistaBtn)));
  let salva = "quadro";
  try { salva = localStorage.getItem("rotina_clientes_vista") || "quadro"; } catch (e) { /* sem armazenamento */ }
  mostrar(salva === "lista" ? "lista" : "quadro");

  // ---- contagem de cada coluna (respeita a busca)
  const contar = () => quadro.querySelectorAll(".kanban-coluna").forEach((col) => {
    col.querySelector("[data-qtd]").textContent = col.querySelectorAll(".cliente-card:not([hidden])").length;
  });
  document.addEventListener("clientes-filtrados", contar);

  // poe o cartao na coluna em ordem alfabetica
  const colocar = (cartao, area) => {
    const nome = cartao.querySelector(".cliente-nome").textContent.toLocaleLowerCase("pt-BR");
    const depois = [...area.querySelectorAll(".cliente-card")].find((c) => c !== cartao && c.querySelector(".cliente-nome").textContent.toLocaleLowerCase("pt-BR").localeCompare(nome, "pt-BR") > 0);
    area.insertBefore(cartao, depois || area.querySelector(".kanban-vazio"));
  };

  // ---- arrastar e soltar
  let arrastado = null;
  quadro.addEventListener("dragstart", (ev) => {
    const cartao = ev.target.closest(".cliente-card");
    if (!cartao) return;
    arrastado = cartao;
    cartao.classList.add("arrastando");
    ev.dataTransfer.effectAllowed = "move";
    ev.dataTransfer.setData("text/plain", cartao.dataset.id);
  });
  quadro.addEventListener("dragend", () => {
    if (arrastado) arrastado.classList.remove("arrastando");
    arrastado = null;
    quadro.querySelectorAll(".soltar-aqui").forEach((a) => a.classList.remove("soltar-aqui"));
  });
  quadro.addEventListener("dragover", (ev) => {
    const area = ev.target.closest("[data-soltar]");
    if (!area || !arrastado) return;
    ev.preventDefault();
    ev.dataTransfer.dropEffect = "move";
    quadro.querySelectorAll(".soltar-aqui").forEach((a) => { if (a !== area) a.classList.remove("soltar-aqui"); });
    area.classList.add("soltar-aqui");
  });
  quadro.addEventListener("dragleave", (ev) => {
    const area = ev.target.closest("[data-soltar]");
    if (area && !area.contains(ev.relatedTarget)) area.classList.remove("soltar-aqui");
  });
  quadro.addEventListener("drop", async (ev) => {
    const area = ev.target.closest("[data-soltar]");
    if (!area || !arrastado) return;
    ev.preventDefault();
    area.classList.remove("soltar-aqui");
    const cartao = arrastado;
    const origem = cartao.closest("[data-soltar]");
    const destino = area.closest(".kanban-coluna").dataset.situacao;
    if (origem === area) return;
    mover(cartao, area, destino, origem);
  });

  async function mover(cartao, area, destino, origem) {
    colocar(cartao, area);
    contar();
    const linha = tabela.querySelector('tr[data-id="' + cartao.dataset.id + '"] [data-selo]');
    const antes = origem.closest(".kanban-coluna").dataset.situacao;
    const marcar = (sit) => { if (linha) { linha.className = "selo selo-" + sit; linha.textContent = NOMES[sit]; } };
    marcar(destino);
    try {
      await chamar("POST", "/api/clientes/" + cartao.dataset.id, { status: destino });
      toast(cartao.querySelector(".cliente-nome").textContent + " → " + NOMES[destino] + (destino === "ativo" ? " (entra na rotina)" : ""));
    } catch (e) {
      colocar(cartao, origem);
      contar();
      marcar(antes);
      toast(e.message, true);
    }
  }

  // para testes e teclado: Rotina.moverCliente(id, situacao)
  window.Rotina.moverCliente = (id, sit) => {
    const cartao = quadro.querySelector('.cliente-card[data-id="' + id + '"]');
    const area = quadro.querySelector('.kanban-coluna[data-situacao="' + sit + '"] [data-soltar]');
    if (cartao && area && cartao.closest("[data-soltar]") !== area) return mover(cartao, area, sit, cartao.closest("[data-soltar]"));
    return Promise.resolve();
  };
})();
