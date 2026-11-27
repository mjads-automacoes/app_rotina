<?php
/** @var Request $r @var array $usuario @var array $pedidos @var array $filtros @var array $esquema @var array $clientes */
// Aba Agentes (27/11/2026): lista unica de pedidos aos agentes, filtrada por setor, status e cliente.
$sel = static fn (string $campo, string $valor): string => (string) ($filtros[$campo] ?? '') === $valor ? 'selected' : '';
?>
<header class="pagina-topo">
  <div>
    <h1>Agentes</h1>
    <p class="muted">Pedidos ao escritório. Cada pedido é de um cliente só; quem pede também aprova a entrega.</p>
  </div>
  <div class="topo-acoes">
    <a class="btn btn-primario" href="/agentes/novo"><?= icone('mais') ?> Novo pedido</a>
  </div>
</header>

<form method="get" action="/agentes" class="filtros-barra agentes-filtros">
  <select name="agente" aria-label="Agente"><option value="">Todos os agentes</option><?php foreach ($esquema['agents'] as $chave => $a): ?><option value="<?= e($chave) ?>" <?= $sel('agente', $chave) ?>><?= e((string) $a['label']) ?></option><?php endforeach; ?></select>
  <select name="status" aria-label="Status"><option value="">Todos os status</option><?php foreach (Pedidos::STATUS as $chave => $nome): ?><option value="<?= e($chave) ?>" <?= $sel('status', $chave) ?>><?= e($nome) ?></option><?php endforeach; ?></select>
  <select name="cliente_id" aria-label="Cliente"><option value="">Todos os clientes</option><?php foreach ($clientes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('cliente_id', (string) $c['id']) ?>><?= e((string) $c['nome']) ?></option><?php endforeach; ?></select>
  <button class="btn btn-contorno" type="submit">Filtrar</button>
</form>

<?php if ($pedidos === []): ?>
  <section class="cartao agentes-vazio">
    <p class="muted">Nenhum pedido por aqui ainda.</p>
    <a class="btn btn-primario" href="/agentes/novo">Fazer o primeiro pedido</a>
  </section>
<?php else: ?>
  <div class="pedidos-lista">
    <?php foreach ($pedidos as $p): ?>
      <a class="cartao pedido-cartao status-<?= e($p['status']) ?>" href="/agentes/<?= (int) $p['id'] ?>">
        <span class="pedido-topo">
          <span class="pedido-codigo"><?= e($p['codigo']) ?></span>
          <span class="selo pedido-status status-<?= e($p['status']) ?>"><?= e($p['status_nome']) ?></span>
        </span>
        <strong class="pedido-titulo"><?= e($p['tipo_nome']) ?> · <?= e((string) $p['cliente_nome']) ?></strong>
        <span class="pedido-meta">
          <span><?= e($p['agente_nome']) ?></span>
          <span>Prazo <?= e(date('d/m', (int) strtotime((string) $p['prazo']))) ?></span>
          <?php if ($p['prioridade'] !== 'normal'): ?><span class="pedido-prio prio-<?= e($p['prioridade']) ?>"><?= e(Pedidos::PRIORIDADES[$p['prioridade']]) ?></span><?php endif; ?>
          <span><?= e((string) ($p['solicitante_nome'] ?? '')) ?></span>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
