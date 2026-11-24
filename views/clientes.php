<?php
/** @var Request $r @var array $usuario @var array $lista @var array $gestores @var array $nomes */
$csrf = (string) $r->sessao->d['csrf'];
$rotuloDia = ['segunda' => 'Seg', 'terca' => 'Ter', 'quarta' => 'Qua', 'quinta' => 'Qui', 'sexta' => 'Sex'];
$rotuloTurno = ['manha' => '08h · Manhã', 'tarde' => '13h · Tarde', 'fechamento' => '17h · Fechamento'];
$admin = $usuario['papel'] === 'admin';
?>
<header class="pagina-topo">
  <div>
    <h1>Clientes</h1>
    <p class="muted">Ficha de cada cliente. Só clientes <strong>ativos</strong> com dia marcado entram na rotina automática.</p>
  </div>
  <div class="topo-acoes">
    <?php if ($lista !== []): ?>
      <div class="segmentado" role="tablist" aria-label="Visualização">
        <button type="button" class="seg is-ativo" data-vista-btn="quadro" role="tab">Quadro</button>
        <button type="button" class="seg" data-vista-btn="lista" role="tab">Lista</button>
      </div>
    <?php endif; ?>
    <label class="busca"><?= icone('busca') ?><input type="search" id="clientesBusca" placeholder="Buscar cliente" autocomplete="off"></label>
  </div>
</header>

<details class="cartao novo-cliente" <?= $lista === [] ? 'open' : '' ?>>
  <summary><?= icone('mais') ?> Novo cliente</summary>
  <form method="post" action="/clientes" class="grade-form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <label>Nome da Empresa <input type="text" name="nome" maxlength="120" required></label>
    <label>Nome do Contato <input type="text" name="contato_nome" maxlength="120"></label>
    <?= seletor_dias([]) ?>
    <label>Turno
      <select name="turno"><?php foreach ($rotuloTurno as $chave => $rotulo): ?><option value="<?= e($chave) ?>"><?= e($rotulo) ?></option><?php endforeach; ?></select>
    </label>
    <?php if ($admin): ?>
      <label>Responsável
        <select name="gestor_id"><?php foreach ($gestores as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) $g['id'] === (int) $usuario['id'] ? 'selected' : '' ?>><?= e((string) $g['nome']) ?></option><?php endforeach; ?></select>
      </label>
    <?php endif; ?>
    <div class="acoes"><button class="btn btn-primario" type="submit">Criar cliente</button></div>
  </form>
</details>

<?php if ($lista === []): ?>
  <p class="muted">Nenhum cliente ainda. Cadastre acima ou traga do ClickUp em <a href="/integracoes">Integrações</a>.</p>
<?php else: ?>
  <?php
  // kanban de clientes (pedido do dono, 24/11/2026): Novo, Ativo, Alerta e Churn; arrastar muda a situacao
  $porSituacao = array_fill_keys(Clientes::SITUACOES, []);
  foreach ($lista as $c) {
      $porSituacao[$c['status']][] = $c;
  }
  $diasDe = static fn (array $c): string => $c['dias'] === [] ? 'sem dia' : implode(' · ', array_map(static fn ($d) => $rotuloDia[$d] ?? $d, $c['dias']));
  $plataformas = static function (array $c): string {
      $h = '';
      foreach (['google', 'meta'] as $pl) {
          if (in_array($pl, $c['plataformas'], true)) {
              $h .= logo_plataforma($pl);
          }
      }

      return $h;
  };
  ?>
  <div id="clientesLista" data-csrf="<?= e($csrf) ?>">
    <div class="kanban" id="clientesQuadro" data-vista="quadro">
      <?php foreach (Clientes::SITUACAO_NOME as $sit => $nomeSit): ?>
        <section class="kanban-coluna kanban-<?= e($sit) ?>" data-situacao="<?= e($sit) ?>" aria-label="<?= e($nomeSit) ?>">
          <header class="kanban-topo"><span class="kanban-ponto"></span><strong><?= e($nomeSit) ?></strong><span class="kanban-qtd" data-qtd><?= count($porSituacao[$sit]) ?></span></header>
          <div class="kanban-cartoes" data-soltar>
            <?php foreach ($porSituacao[$sit] as $c): ?>
              <a class="cartao cliente-card" href="/clientes/<?= (int) $c['id'] ?>" draggable="true" data-id="<?= (int) $c['id'] ?>" data-nome="<?= e(mb_strtolower($c['nome'] . ' ' . $c['empresa'] . ' ' . $c['contato_nome'])) ?>">
                <strong class="cliente-nome"><?= e((string) $c['nome']) ?></strong>
                <span class="muted"><?= e($c['contato_nome'] !== '' ? $c['contato_nome'] : 'Sem contato') ?></span>
                <span class="cliente-card-rodape">
                  <span class="rodape-linha"><span><?= e($diasDe($c)) ?></span><span><?= e($rotuloTurno[$c['turno']] ?? '') ?></span></span>
                  <span class="rodape-linha"><span><?= e((string) ($nomes[$c['gestor_id']] ?? '')) ?></span><span class="plats"><?= $plataformas($c) ?></span></span>
                </span>
              </a>
            <?php endforeach; ?>
            <p class="kanban-vazio muted">Arraste um cliente para cá</p>
          </div>
        </section>
      <?php endforeach; ?>
    </div>

    <div class="tabela-clientes-caixa cartao" id="clientesTabela" data-vista="lista" hidden>
      <table class="tabela tabela-clientes">
        <thead><tr><th>Cliente</th><th>Contato</th><th>Situação</th><th>Dias</th><th>Turno</th><th>Responsável</th><th>Plataformas</th></tr></thead>
        <?php foreach (Clientes::SITUACAO_NOME as $sit => $nomeSit): ?>
          <tbody class="lista-grupo lista-<?= e($sit) ?>" data-situacao="<?= e($sit) ?>">
            <tr class="lista-grupo-topo"><th colspan="7"><span class="kanban-ponto"></span><?= e($nomeSit) ?><span class="kanban-qtd" data-qtd><?= count($porSituacao[$sit]) ?></span></th></tr>
            <?php foreach ($porSituacao[$sit] as $c): ?>
              <tr data-id="<?= (int) $c['id'] ?>" data-nome="<?= e(mb_strtolower($c['nome'] . ' ' . $c['empresa'] . ' ' . $c['contato_nome'])) ?>">
                <td><a class="tabela-cliente" href="/clientes/<?= (int) $c['id'] ?>"><?= e((string) $c['nome']) ?></a></td>
                <td class="muted"><?= e($c['contato_nome'] !== '' ? $c['contato_nome'] : '—') ?></td>
                <td><span class="selo selo-<?= e((string) $c['status']) ?>" data-selo><?= e(Clientes::SITUACAO_NOME[$c['status']] ?? $c['status']) ?></span></td>
                <td><?= e($diasDe($c)) ?></td>
                <td><?= e($rotuloTurno[$c['turno']] ?? '') ?></td>
                <td><?= e((string) ($nomes[$c['gestor_id']] ?? '')) ?></td>
                <td><span class="plats"><?= $plataformas($c) ?></span></td>
              </tr>
            <?php endforeach; ?>
            <tr class="lista-vazio"><td colspan="7" class="muted">Nenhum cliente</td></tr>
          </tbody>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
<?php endif; ?>
