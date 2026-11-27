<?php
/** @var Request $r @var array $usuario @var array $pedidos @var array $filtros @var array $esquema @var array $clientes @var array $resumo */
// Aba Agentes (27/11/2026): lista unica de pedidos aos agentes, filtrada por setor, status e cliente.
$sel = static fn (string $campo, string $valor): string => (string) ($filtros[$campo] ?? '') === $valor ? 'selected' : '';
?>
<header class="pagina-topo">
  <div>
    <h1>Agentes</h1>
  </div>
</header>

<?php // os agentes como personagens (pedido do dono, 27/11/2026): avatar, nome, funcao, frase, numeros e "Pedir" ?>
<section class="agentes-time" aria-label="Time de agentes">
  <?php foreach ($esquema['agents'] as $chave => $a): $pp = Pedidos::PERSONAGENS[$chave] ?? ['avatar' => '🤖', 'cor' => '#8f99a3', 'frase' => '']; $nr = $resumo[$chave] ?? ['fila' => 0, 'fazendo' => 0, 'aprovar' => 0, 'concluidos' => 0]; ?>
    <article class="agente-card" data-agente="<?= e($chave) ?>" data-agente-cor="<?= e($pp['cor']) ?>">
      <div class="agente-topo">
        <span class="agente-avatar" aria-hidden="true"><span><?= e($pp['avatar']) ?></span></span>
      </div>
      <h2 class="agente-funcao"><?= e((string) $a['label']) ?></h2>
      <p class="agente-fala"><?= e($pp['frase']) ?></p>
      <div class="agente-status">
        <?php if ($nr['fazendo'] > 0): ?><span class="agente-estado is-trabalhando">● Trabalhando em <?= $nr['fazendo'] ?></span><?php else: ?><span class="agente-estado">● Disponível</span><?php endif; ?>
      </div>
      <dl class="agente-numeros">
        <div><dt>Na fila</dt><dd><?= $nr['fila'] ?></dd></div>
        <div><dt>Para aprovar</dt><dd><?= $nr['aprovar'] ?></dd></div>
        <div><dt>Entregues</dt><dd><?= $nr['concluidos'] ?></dd></div>
      </dl>
      <a class="btn agente-pedir" href="/agentes/novo?agente=<?= e($chave) ?>">Fazer pedido</a>
    </article>
  <?php endforeach; ?>
</section>

<h2 class="agentes-subtitulo">Pedidos</h2>
<form method="get" action="/agentes" class="filtros-barra agentes-filtros">
  <select name="agente" aria-label="Agente"><option value="">Todos os agentes</option><?php foreach ($esquema['agents'] as $chave => $a): ?><option value="<?= e($chave) ?>" <?= $sel('agente', $chave) ?>><?= e((string) $a['label']) ?></option><?php endforeach; ?></select>
  <select name="status" aria-label="Status"><option value="">Todos os status</option><?php foreach (Pedidos::STATUS as $chave => $nome): ?><option value="<?= e($chave) ?>" <?= $sel('status', $chave) ?>><?= e($nome) ?></option><?php endforeach; ?></select>
  <select name="cliente_id" aria-label="Cliente"><option value="">Todos os clientes</option><?php foreach ($clientes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('cliente_id', (string) $c['id']) ?>><?= e((string) $c['nome']) ?></option><?php endforeach; ?></select>
  <button class="btn btn-contorno" type="submit">Filtrar</button>
</form>

<?php if ($pedidos !== []): ?>
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
