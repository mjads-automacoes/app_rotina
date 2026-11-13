<?php
/** @var Request $r @var array $usuario @var array $cliente @var array $notas @var array $tarefas @var string $gestor @var array $gestores */
$csrf = (string) $r->sessao->d['csrf'];
$rotuloDia = ['segunda' => 'Seg', 'terca' => 'Ter', 'quarta' => 'Qua', 'quinta' => 'Qui', 'sexta' => 'Sex'];
$rotuloTurno = ['manha' => '08h · Manhã', 'tarde' => '13h · Tarde', 'fechamento' => '17h · Fechamento'];
$plataformas = ['google' => 'Google Ads', 'meta' => 'Meta Ads'];
$admin = $usuario['papel'] === 'admin';
$c = $cliente;
$clickup = $c['clickup_dados'] ? (json_decode((string) $c['clickup_dados'], true) ?: []) : [];
?>
<header class="pagina-topo">
  <div class="cliente-titulo">
    <span class="avatar avatar-lg"><?= e(mb_strtoupper(mb_substr((string) $c['nome'], 0, 2))) ?></span>
    <div>
      <p class="muted migalha"><a href="/clientes">Clientes</a> / ficha</p>
      <h1><?= e((string) $c['nome']) ?></h1>
      <p class="muted"><?= $c['contato_nome'] !== '' ? e((string) $c['contato_nome']) . ' · ' : '' ?><span class="selo selo-<?= e((string) $c['status']) ?>"><?= e(ucfirst((string) $c['status'])) ?></span> · responsável: <?= e($gestor) ?></p>
    </div>
  </div>
  <div class="topo-acoes">
  </div>
</header>

<div class="ficha-grade">
  <section class="cartao">
    <h2>Dados do cliente</h2>
    <form method="post" action="/clientes/<?= (int) $c['id'] ?>" class="grade-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

      <h3 class="form-secao largo">Empresa e contato</h3>
      <label>Nome da Empresa <input type="text" name="nome" value="<?= e((string) $c['nome']) ?>" maxlength="120" required></label>
      <label>Nome do Contato <input type="text" name="contato_nome" value="<?= e((string) $c['contato_nome']) ?>" maxlength="120"></label>
      <label>Responsável
        <select name="gestor_id" <?= $admin ? '' : 'disabled' ?>><?php foreach ($gestores as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) $g['id'] === (int) $c['gestor_id'] ? 'selected' : '' ?>><?= e((string) $g['nome']) ?></option><?php endforeach; ?></select>
      </label>
      <label>Telefone <input type="text" name="contato_telefone" value="<?= e((string) $c['contato_telefone']) ?>" maxlength="30"></label>
      <label>E-mail <input type="email" name="contato_email" value="<?= e((string) $c['contato_email']) ?>"></label>
      <label>Orçamento para Anúncios <input type="text" name="orcamento" value="<?= e((string) $c['orcamento']) ?>" maxlength="60" placeholder="Ex.: R$ 3.000/mês"></label>
      <label class="largo">Site do Cliente <input type="url" name="link" value="<?= e((string) $c['link']) ?>" maxlength="500" placeholder="https://"></label>

      <h3 class="form-secao largo">Plataformas</h3>
      <div class="plataformas largo">
        <input type="hidden" name="plataformas_enviado" value="1">
        <?php foreach ($plataformas as $chave => $rotulo): ?>
          <label class="plat">
            <input type="checkbox" name="plataformas[]" value="<?= e($chave) ?>" <?= in_array($chave, $c['plataformas'], true) ? 'checked' : '' ?>>
            <span class="plat-cartao"><?= logo_plataforma($chave) ?><span><?= e($rotulo) ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>

      <h3 class="form-secao largo">Rotina</h3>
      <?= seletor_dias($c['dias']) ?>
      <label>Turno
        <select name="turno"><?php foreach ($rotuloTurno as $chave => $rotulo): ?><option value="<?= e($chave) ?>" <?= $c['turno'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select>
      </label>
      <label>Situação
        <select name="status"><?php foreach (['ativo' => 'Ativo', 'novo' => 'Novo', 'pausado' => 'Pausado'] as $chave => $rotulo): ?><option value="<?= e($chave) ?>" <?= $c['status'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select>
      </label>

      <h3 class="form-secao largo">Observações</h3>
      <label class="largo"><span class="sr-only">Observações</span><textarea name="observacoes" rows="4" maxlength="2000"><?= e((string) $c['observacoes']) ?></textarea></label>
      <div class="acoes">
        <button class="btn btn-primario" type="submit">Salvar</button>
        <button class="btn btn-perigo" type="submit" formaction="/clientes/<?= (int) $c['id'] ?>/excluir" data-confirmar="Excluir este cliente? As tarefas dele ficam, sem cliente.">Excluir</button>
      </div>
    </form>
  </section>

  <div class="coluna">
    <section class="cartao">
      <h2>Tarefas deste cliente</h2>
      <?php if ($tarefas === []): ?>
        <p class="muted">Nenhuma tarefa ainda.</p>
      <?php else: ?>
        <ul class="lista-simples">
          <?php foreach (array_slice($tarefas, 0, 12) as $t): ?>
            <li>
              <span class="ponto" data-cor="<?= e((string) $t['status_cor']) ?>"></span>
              <span class="grow"><?= e((string) $t['titulo']) ?></span>
              <span class="muted"><?= e((string) $t['status_nome']) ?><?= $t['prazo'] ? ' · ' . e(date('d/m', (int) strtotime((string) $t['prazo']))) : '' ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="cartao">
      <h2>Notas</h2>
      <form method="post" action="/clientes/<?= (int) $c['id'] ?>/notas" class="nota-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <textarea name="texto" rows="2" maxlength="2000" placeholder="Anote algo sobre o cliente…" required></textarea>
        <button class="btn btn-primario" type="submit">Adicionar</button>
      </form>
      <?php foreach ($notas as $nota): ?>
        <article class="nota">
          <header><strong><?= e((string) $nota['autor']) ?></strong> <span class="muted"><?= e(date('d/m/Y H:i', (int) strtotime((string) $nota['criado_em']))) ?><?= $nota['origem'] === 'clickup' ? ' · do ClickUp' : '' ?></span></header>
          <p><?= nl2br(e((string) $nota['texto'])) ?></p>
        </article>
      <?php endforeach; ?>
    </section>

    <?php if ($clickup): ?>
      <section class="cartao">
        <h2>Dados do ClickUp</h2>
        <p class="muted">Guardados na importação. O ClickUp não é mais necessário para consultar isto.</p>
        <dl class="def">
          <dt>Status</dt><dd><?= e((string) ($clickup['status'] ?? '')) ?></dd>
          <?php if (!empty($clickup['responsaveis'])): ?><dt>Responsáveis</dt><dd><?= e(implode(', ', (array) $clickup['responsaveis'])) ?></dd><?php endif; ?>
          <?php if (!empty($clickup['tags'])): ?><dt>Etiquetas</dt><dd><?= e(implode(', ', (array) $clickup['tags'])) ?></dd><?php endif; ?>
          <?php foreach ((array) ($clickup['campos'] ?? []) as $campo): ?><dt><?= e((string) $campo['nome']) ?></dt><dd><?= e((string) $campo['valor']) ?></dd><?php endforeach; ?>
        </dl>
      </section>
    <?php endif; ?>
  </div>
</div>
