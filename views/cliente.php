<?php
/** @var Request $r @var array $usuario @var array $cliente @var array $notas @var array $tarefas @var string $gestor @var array $gestores @var string $hoje */
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
      <div class="cliente-nome-linha">
        <h1 data-cliente-nome><?= e((string) $c['nome']) ?></h1>
        <form method="post" action="/clientes/<?= (int) $c['id'] ?>/excluir" class="form-lixeira">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-icone btn-lixeira" type="submit" data-confirmar="Excluir o cliente <?= e((string) $c['nome']) ?>? As tarefas em aberto dele também serão apagadas." aria-label="Excluir cliente" title="Excluir cliente"><?= icone('lixeira') ?></button>
        </form>
      </div>
      <p class="muted"><?= $c['contato_nome'] !== '' ? e((string) $c['contato_nome']) . ' · ' : '' ?><span class="selo selo-<?= e((string) $c['status']) ?>"><?= e(ucfirst((string) $c['status'])) ?></span> · responsável: <?= e($gestor) ?></p>
    </div>
  </div>
  <div class="topo-acoes">
  </div>
</header>

<div class="ficha-grade">
  <section class="cartao">
    <div class="cartao-topo-linha">
      <h2>Dados do cliente</h2>
    </div>
    <form method="post" action="/clientes/<?= (int) $c['id'] ?>" class="grade-form" data-autosalvar="/api/clientes/<?= (int) $c['id'] ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

      <h3 class="form-secao largo">Empresa e contato</h3>
      <label>Nome da Empresa <input type="text" name="nome" data-sem-emoji value="<?= e((string) $c['nome']) ?>" maxlength="120" required></label>
      <label>Nome do Contato <input type="text" name="contato_nome" data-sem-emoji value="<?= e((string) $c['contato_nome']) ?>" maxlength="120"></label>
      <label>Responsável
        <select name="gestor_id" <?= $admin ? '' : 'disabled' ?>><?php foreach ($gestores as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) $g['id'] === (int) $c['gestor_id'] ? 'selected' : '' ?>><?= e((string) $g['nome']) ?></option><?php endforeach; ?></select>
      </label>
      <label>Telefone <input type="text" name="contato_telefone" data-sem-emoji value="<?= e((string) $c['contato_telefone']) ?>" maxlength="30"></label>
      <label>E-mail <input type="email" name="contato_email" value="<?= e((string) $c['contato_email']) ?>"></label>
      <label>Orçamento para Anúncios <input type="text" name="orcamento" data-sem-emoji value="<?= e((string) $c['orcamento']) ?>" maxlength="60" placeholder="Ex.: R$ 3.000/mês"></label>
      <?php /* "Site do Cliente" trocado por CNPJ | Cidade | Estado (pedido do dono, 24/11/2026); o site antigo continua guardado no banco */ ?>
      <label>CNPJ <input type="text" name="cnpj" data-sem-emoji value="<?= e((string) $c['cnpj']) ?>" maxlength="18" inputmode="numeric" placeholder="00.000.000/0000-00" data-cnpj autocomplete="off"></label>
      <label>Cidade <input type="text" name="cidade" data-sem-emoji value="<?= e((string) $c['cidade']) ?>" maxlength="80" placeholder="Ex.: Santos"></label>
      <label>Estado
        <select name="estado"><option value="">—</option><?php foreach (Clientes::UFS as $uf): ?><option value="<?= e($uf) ?>" <?= $c['estado'] === $uf ? 'selected' : '' ?>><?= e($uf) ?></option><?php endforeach; ?></select>
      </label>

      <?php // Plataformas | Entregaveis, separados por uma linha (pedido do dono, 24/11/2026)
      $entregaveisMarcados = array_values(array_filter(array_keys(Clientes::ENTREGAVEIS), static fn (string $x): bool => in_array($x, $c['entregaveis'], true)));
      $rotuloEntregaveis = $entregaveisMarcados === [] ? 'Selecione os entregáveis' : implode(', ', array_map(static fn (string $x): string => Clientes::ENTREGAVEIS[$x], $entregaveisMarcados)); ?>
      <div class="largo plat-entregas">
        <div class="plat-entregas-col">
          <h3 class="form-secao">Plataformas</h3>
          <div class="plataformas">
            <input type="hidden" name="plataformas_enviado" value="1">
            <?php foreach ($plataformas as $chave => $rotulo): ?>
              <label class="plat">
                <input type="checkbox" name="plataformas[]" value="<?= e($chave) ?>" <?= in_array($chave, $c['plataformas'], true) ? 'checked' : '' ?>>
                <span class="plat-cartao"><?= logo_plataforma($chave) ?><span><?= e($rotulo) ?></span></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="plat-entregas-col plat-entregas-dir">
          <h3 class="form-secao">Entregáveis</h3>
          <details class="multi" data-multi data-multi-vazio="Selecione os entregáveis">
            <summary><span data-multi-rotulo><?= e($rotuloEntregaveis) ?></span></summary>
            <div class="multi-lista">
              <input type="hidden" name="entregaveis_enviado" value="1">
              <?php foreach (Clientes::ENTREGAVEIS as $chave => $rotulo): ?>
                <label class="check"><input type="checkbox" name="entregaveis[]" value="<?= e($chave) ?>" data-curto="<?= e($rotulo) ?>" <?= in_array($chave, $entregaveisMarcados, true) ? 'checked' : '' ?>> <?= e($rotulo) ?></label>
              <?php endforeach; ?>
            </div>
          </details>
        </div>
      </div>

      <h3 class="form-secao largo">Rotina</h3>
      <?= seletor_dias($c['dias']) ?>
      <label>Turno
        <select name="turno"><?php foreach ($rotuloTurno as $chave => $rotulo): ?><option value="<?= e($chave) ?>" <?= $c['turno'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select>
      </label>
      <label>Situação
        <select name="status"><?php foreach (Clientes::SITUACAO_NOME as $chave => $rotulo): ?><option value="<?= e($chave) ?>" <?= $c['status'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select>
      </label>

      <?php $temObs = trim((string) $c['observacoes']) !== ''; ?>
      <div class="form-secao largo obs-topo">
        <h3>Observações</h3>
        <div class="obs-barra" role="toolbar" aria-label="Formatar observações" data-obs-barra <?= $temObs ? 'hidden' : '' ?>>
          <button type="button" class="obs-fmt" data-formato="titulo" title="Título" aria-label="Título">#</button>
          <button type="button" class="obs-fmt obs-fmt-b" data-formato="negrito" title="Negrito" aria-label="Negrito">B</button>
          <button type="button" class="obs-fmt obs-fmt-i" data-formato="italico" title="Itálico" aria-label="Itálico">I</button>
          <button type="button" class="obs-fmt obs-fmt-u" data-formato="sublinhado" title="Sublinhado" aria-label="Sublinhado">U</button>
        </div>
      </div>
      <?php if ($temObs): ?>
        <div class="largo obs-ver" data-obs-ver>
          <div class="texto-rico recolhivel" data-recolher><?= texto_rico((string) $c['observacoes']) ?></div>
          <button class="btn btn-contorno btn-obs-editar" type="button" data-obs-editar>Editar observações</button>
        </div>
      <?php endif; ?>
      <label class="largo" data-obs-campo <?= $temObs ? 'hidden' : '' ?>><span class="sr-only">Observações</span><textarea name="observacoes" rows="<?= $temObs ? 14 : 4 ?>" maxlength="<?= Clientes::OBSERVACOES_MAX ?>"><?= e((string) $c['observacoes']) ?></textarea></label>
    </form>
  </section>

  <div class="coluna">
    <section class="cartao">
      <h2>Tarefas deste cliente</h2>
      <form method="post" action="/clientes/<?= (int) $c['id'] ?>/tarefas" class="tarefa-nova">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label class="grow"><span class="sr-only">Nova tarefa</span><input type="text" name="titulo" maxlength="200" placeholder="Nova tarefa para este cliente…" required></label>
        <div class="calendario" data-calendario data-min="<?= e($hoje) ?>">
          <input type="hidden" name="prazo" value="">
          <button type="button" class="calendario-botao" data-cal-abrir aria-haspopup="dialog" aria-expanded="false" title="Prazo (opcional)"><?= icone('calendario') ?><span data-cal-texto>Prazo</span></button>
        </div>
        <button class="btn btn-primario" type="submit">Add +</button>
      </form>
      <?php if ($tarefas === []): ?>
        <p class="muted">Nenhuma tarefa ainda.</p>
      <?php else: ?>
        <ul class="lista-simples">
          <?php foreach (array_slice($tarefas, 0, 12) as $t): ?>
            <li>
              <span class="ponto" data-cor="<?= e((string) $t['status_cor']) ?>"></span>
              <span class="grow tarefa-texto">
                <span><?= e((string) $t['titulo']) ?></span>
                <small class="muted"><?= e((string) $t['status_nome']) ?><?= $t['prazo'] ? ' · ' . e(date('d/m', (int) strtotime((string) $t['prazo']))) : '' ?></small>
              </span>
              <form method="post" action="/clientes/<?= (int) $c['id'] ?>/tarefas/<?= (int) $t['id'] ?>/excluir" class="tarefa-excluir">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <button class="btn btn-icone btn-excluir-tarefa" type="submit" data-confirmar="Excluir a tarefa &quot;<?= e((string) $t['titulo']) ?>&quot;?" aria-label="Excluir tarefa" title="Excluir tarefa">✕</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="cartao">
      <h2>Notas</h2>
      <form method="post" action="/clientes/<?= (int) $c['id'] ?>/notas" class="nota-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <textarea name="texto" rows="2" maxlength="<?= Clientes::NOTA_MAX ?>" placeholder="<?= empty($c['clickup_id']) ? 'Anote algo sobre o cliente…' : 'Anote algo sobre o cliente (vai também para os comentários do ClickUp)…' ?>" required></textarea>
        <button class="btn btn-primario" type="submit">Add +</button>
      </form>
      <?php foreach ($notas as $nota): ?>
        <article class="nota">
          <header><strong><?= e((string) $nota['autor']) ?></strong> <span class="muted"><?= e(date('d/m/Y H:i', (int) strtotime((string) $nota['criado_em']))) ?><?= $nota['origem'] === 'clickup' ? ' · do ClickUp' : '' ?></span></header>
          <div class="texto-rico"><?= texto_rico((string) $nota['texto']) ?></div>
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
