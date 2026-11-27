<?php
/** @var Request $r @var array $usuario @var array $pedido @var array $esquema */
// Detalhe do pedido aos agentes: campos enviados, linha do tempo (status, perguntas, entregas) e as acoes de quem pediu.
$csrf = (string) $r->sessao->d['csrf'];
$p = $pedido;
$form = $esquema['agents'][$p['agente']]['forms'][$p['tipo']] ?? ['fields' => []];
$rotulos = [];
$opcoes = [];
foreach (array_merge($esquema['common_fields'], $form['fields']) as $campo) {
    $rotulos[$campo['id']] = $campo['label'];
    foreach ((array) ($campo['options'] ?? []) as $o) {
        if (is_array($o) && isset($o['value'])) {
            $opcoes[$campo['id']][(string) $o['value']] = (string) $o['label'];
        }
    }
}
$nomeOpcao = static fn (string $campo, string $v): string => $opcoes[$campo][$v] ?? str_replace('_', ' ', $v);
$valorTexto = static function (string $campo, mixed $v) use (&$valorTexto, $nomeOpcao): string {
    if (is_bool($v)) {
        return $v ? 'Sim' : 'Não';
    }
    if (!is_array($v)) {
        return $nomeOpcao($campo, (string) $v);
    }
    if (!empty($v['sem_oferta'])) {
        return 'Sem oferta';
    }
    if (!empty($v['nenhum'])) {
        return ucfirst(str_replace('_', ' ', (string) $v['nenhum']));
    }
    if (isset($v['texto'], $v['validade']) && $v['texto'] !== '') {
        return $v['texto'] . ' (válida até ' . date('d/m/Y', (int) strtotime((string) $v['validade'])) . ')';
    }
    $partes = [];
    foreach ($v as $k => $x) {
        $t = $valorTexto($campo, $x);
        if ($t !== '') {
            $partes[] = is_string($k) && !in_array($k, ['opcao', 'texto', 'opcoes', 'link'], true) ? ucfirst(str_replace('_', ' ', $k)) . ': ' . $t : $t;
        }
    }
    return implode(' · ', $partes);
};
$souAprovador = (int) $p['aprovador_id'] === (int) $usuario['id'] || $usuario['papel'] === 'admin';
$souSolicitante = (int) $p['solicitante_id'] === (int) $usuario['id'];
$aberto = !in_array($p['status'], ['concluido', 'cancelado'], true);
$rotuloEvento = ['criado' => 'Pedido enviado', 'status' => 'Status', 'pergunta' => 'Pergunta do agente', 'resposta' => 'Resposta', 'entrega' => 'Entrega', 'ajuste' => 'Ajuste pedido', 'aprovado' => 'Aprovado', 'cancelado' => 'Cancelado', 'comentario' => 'Comentário'];
?>
<header class="pagina-topo">
  <div>
    <p class="muted"><a href="/agentes">Agentes</a> / <?= e($p['codigo']) ?></p>
    <h1><?= e($p['tipo_nome']) ?> · <?= e((string) $p['cliente_nome']) ?></h1>
    <p class="pedido-meta">
      <span class="selo pedido-status status-<?= e($p['status']) ?>"><?= e($p['status_nome']) ?></span>
      <span><?= e($p['agente_nome']) ?></span>
      <span>Prazo <?= e(date('d/m/Y', (int) strtotime((string) $p['prazo']))) ?></span>
      <span class="pedido-prio prio-<?= e($p['prioridade']) ?>"><?= e(Pedidos::PRIORIDADES[$p['prioridade']] ?? $p['prioridade']) ?></span>
      <span>Pedido por <?= e((string) ($p['solicitante_nome'] ?? '')) ?></span>
    </p>
  </div>
</header>

<?php if (!empty($esquema['agents'][$p['agente']]['notice'])): ?>
  <div class="pedido-aviso"><?= e((string) $esquema['agents'][$p['agente']]['notice']) ?></div>
<?php endif; ?>

<div class="ficha-grade pedido-grade">
  <section class="cartao">
    <h2>Linha do tempo</h2>
    <ol class="pedido-linha">
      <?php foreach ($p['eventos'] as $ev): ?>
        <li class="evento evento-<?= e((string) $ev['tipo']) ?>">
          <header><strong><?= e($rotuloEvento[$ev['tipo']] ?? $ev['tipo']) ?><?= $ev['tipo'] === 'entrega' && isset($ev['dados']['versao']) ? ' v' . (int) $ev['dados']['versao'] : '' ?></strong> <span class="muted"><?= e((string) $ev['autor']) ?> · <?= e(date('d/m H:i', (int) strtotime((string) $ev['criado_em']))) ?></span></header>
          <div class="texto-rico"><?= texto_rico((string) $ev['texto']) ?></div>
          <?php if ($ev['tipo'] === 'entrega'): $d = $ev['dados']; ?>
            <?php foreach (['arquivos' => 'Arquivos', 'links' => 'Links'] as $k => $titulo): if (!empty($d[$k])): ?>
              <p class="evento-sub"><?= e($titulo) ?></p>
              <ul class="evento-links"><?php foreach ($d[$k] as $l): ?><li><a href="<?= e((string) $l['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $l['nome']) ?></a></li><?php endforeach; ?></ul>
            <?php endif; endforeach; ?>
            <?php if (!empty($d['textos'])): ?><p class="evento-sub">Textos para conferir</p><?php foreach ($d['textos'] as $t): ?><div class="evento-texto texto-rico"><?= texto_rico((string) $t) ?></div><?php endforeach; ?><?php endif; ?>
            <?php if (!empty($d['checklist'])): ?><p class="evento-sub">Confira antes de aprovar</p><ul class="evento-check"><?php foreach ($d['checklist'] as $t): ?><li><?= e((string) $t) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>

    <?php if ($p['status'] === 'falta_informacao' && ($souSolicitante || $souAprovador)): ?>
      <form method="post" action="/agentes/<?= (int) $p['id'] ?>/responder" class="pedido-acao pedido-responder">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Sua resposta ao agente<textarea name="texto" rows="3" required></textarea></label>
        <button class="btn btn-primario" type="submit">Responder</button>
      </form>
    <?php endif; ?>

    <?php if ($p['status'] === 'para_aprovacao' && $souAprovador): ?>
      <div class="pedido-acao pedido-aprovar">
        <form method="post" action="/agentes/<?= (int) $p['id'] ?>/aprovar">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-primario" type="submit">Aprovar</button>
        </form>
        <form method="post" action="/agentes/<?= (int) $p['id'] ?>/ajuste" class="pedido-ajuste">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <textarea name="texto" rows="2" placeholder="O que precisa mudar?" required></textarea>
          <button class="btn btn-contorno" type="submit">Pedir ajuste</button>
        </form>
      </div>
    <?php endif; ?>

    <form method="post" action="/agentes/<?= (int) $p['id'] ?>/comentar" class="pedido-acao pedido-comentar">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <textarea name="texto" rows="2" placeholder="Comentar no pedido…" required></textarea>
      <button class="btn btn-contorno btn-mini" type="submit">Comentar</button>
    </form>
  </section>

  <div class="coluna">
    <section class="cartao">
      <h2>O que foi pedido</h2>
      <dl class="pedido-campos">
        <?php if (($p['campos']['prioridade_motivo'] ?? '') !== ''): ?><dt>Motivo da urgência</dt><dd><?= e((string) $p['campos']['prioridade_motivo']) ?></dd><?php endif; ?>
        <?php foreach ($p['campos'] as $campo => $valor): if ($campo === 'prioridade_motivo') { continue; } $t = $valorTexto((string) $campo, $valor); if ($t === '') { continue; } ?>
          <dt><?= e((string) ($rotulos[$campo] ?? $campo)) ?></dt>
          <dd><?php if (preg_match('#^https?://\S+$#', $t)): ?><a href="<?= e($t) ?>" target="_blank" rel="noopener noreferrer"><?= e($t) ?></a><?php else: ?><?= nl2br(e($t)) ?><?php endif; ?></dd>
        <?php endforeach; ?>
      </dl>
    </section>

    <?php if ($aberto && ($souSolicitante || $souAprovador)): ?>
      <section class="cartao">
        <h2>Cancelar pedido</h2>
        <form method="post" action="/agentes/<?= (int) $p['id'] ?>/cancelar" class="pedido-acao">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <textarea name="texto" rows="2" placeholder="Motivo do cancelamento" required></textarea>
          <button class="btn btn-perigo btn-mini" type="submit" data-confirmar="Cancelar este pedido?">Cancelar pedido</button>
        </form>
      </section>
    <?php endif; ?>
  </div>
</div>
