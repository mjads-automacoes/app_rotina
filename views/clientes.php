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
  <p class="muted">Nenhum cliente ainda. Cadastre acima ou traga do ClickUp em <a href="/importar">Importar</a>.</p>
<?php else: ?>
  <div class="cards-clientes" id="clientesLista">
    <?php foreach ($lista as $c): ?>
      <a class="cartao cliente-card" href="/clientes/<?= (int) $c['id'] ?>" data-nome="<?= e(mb_strtolower($c['nome'] . ' ' . $c['empresa'] . ' ' . $c['contato_nome'])) ?>">
        <span class="cliente-card-topo">
          <span class="avatar avatar-md"><?= e(mb_strtoupper(mb_substr((string) $c['nome'], 0, 2))) ?></span>
          <span class="selo selo-<?= e((string) $c['status']) ?>"><?= e(ucfirst((string) $c['status'])) ?></span>
        </span>
        <strong class="cliente-nome"><?= e((string) $c['nome']) ?></strong>
        <span class="muted"><?= e($c['contato_nome'] !== '' ? $c['contato_nome'] : ($c['descricao'] !== '' ? $c['descricao'] : 'Sem contato')) ?></span>
        <span class="cliente-card-rodape">
          <span class="rodape-linha"><span><?= $c['dias'] === [] ? 'sem dia' : e(implode(' · ', array_map(static fn ($d) => $rotuloDia[$d] ?? $d, $c['dias']))) ?></span><span><?= e($rotuloTurno[$c['turno']] ?? '') ?></span></span>
          <span class="rodape-linha"><span><?= e((string) ($nomes[$c['gestor_id']] ?? '')) ?></span><span class="plats"><?php foreach (['google', 'meta'] as $pl): ?><?php if (in_array($pl, $c['plataformas'], true)): ?><?= logo_plataforma($pl) ?><?php endif; ?><?php endforeach; ?></span></span>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
