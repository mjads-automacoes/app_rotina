<?php
/** @var Request $r @var array $usuario @var int $clienteInicial @var string $agenteInicial */
// Novo pedido: o formulario e montado pelo agentes.js a partir da especificacao (src/agentes-formularios.json).
?>
<header class="pagina-topo">
  <div>
    <p class="muted"><a href="/agentes">Agentes</a> / novo pedido</p>
    <h1>Novo pedido</h1>
  </div>
</header>
<div id="pedidoNovo" class="pedido-novo" data-cliente="<?= (int) $clienteInicial ?>" data-agente="<?= e($agenteInicial) ?>">
  <p class="muted">Carregando o formulário…</p>
</div>
