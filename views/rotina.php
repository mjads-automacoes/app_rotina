<?php /** @var Request $r @var array $usuario @var array $gestores @var DateTimeImmutable $hoje */ ?>
<section id="rotina" data-papel="<?= e((string) $usuario['papel']) ?>" data-hoje="<?= e($hoje->format('Y-m-d')) ?>" data-hora="<?= e($hoje->format('G')) ?>">
<div id="painelRotina">
  <header class="pagina-topo">
    <div class="titulo-abas">
      <h1>Rotina</h1>
      <?php if ($usuario['papel'] === 'admin'): ?>
        <label class="sr-only" for="rotinaGestor">Gestor</label>
        <select id="rotinaGestor" class="campo-curto">
          <?php foreach ($gestores as $g): ?>
            <option value="<?= (int) $g['id'] ?>" <?= (int) $g['id'] === (int) $usuario['id'] ? 'selected' : '' ?>><?= e((string) $g['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>
    <div class="topo-acoes">
      <button class="btn btn-icone" type="button" id="rotinaAtualizar" title="Atualizar" aria-label="Atualizar"><?= icone('atualizar') ?></button>
    </div>
  </header>

  <div class="rotina-controles">
    <div class="rotina-data" id="rotinaDias" aria-label="Dia"></div>
  </div>

  <div class="aviso aviso-erro" id="rotinaErro" hidden role="alert"></div>

  <div class="rotina-grade" id="rotinaGrade">
    <section class="cartao rotina-checklist" id="rotinaChecklist" aria-label="Checklist do turno"></section>
    <section class="cartao rotina-foco" id="rotinaFoco" aria-live="polite"></section>
    <aside class="cartao rotina-clientes" id="rotinaClientes" aria-label="Clientes do dia"></aside>
  </div>
</div>

<div id="tarefas" hidden>
  <header class="pagina-topo">
    <div class="titulo-abas">
      <h1>Tarefas</h1>
      <div class="segmentado" role="tablist" aria-label="Seção">
        <button type="button" class="seg is-ativo" data-aba="rotina" role="tab">Rotina do dia</button>
        <button type="button" class="seg" data-aba="tarefas" role="tab">Tarefas</button>
      </div>
    </div>
    <div class="topo-acoes">
      <div class="segmentado" role="tablist" aria-label="Visualização">
        <button type="button" class="seg is-ativo" data-vista="lista" role="tab">Lista</button>
        <button type="button" class="seg" data-vista="quadro" role="tab">Quadro</button>
      </div>
      <button class="btn btn-primario" type="button" id="tarefaNova"><?= icone('mais') ?> Nova tarefa</button>
    </div>
  </header>

  <div class="filtros-barra" id="filtros">
    <label class="busca"><?= icone('busca') ?><input type="search" id="fBusca" placeholder="Buscar tarefa ou cliente" autocomplete="off"></label>
    <select id="fLista" aria-label="Lista"></select>
    <select id="fResponsavel" aria-label="Responsável"><option value="">Responsável</option></select>
    <select id="fPrioridade" aria-label="Prioridade"><option value="">Prioridade</option></select>
    <select id="fCliente" aria-label="Cliente"><option value="">Cliente</option></select>
    <label class="check"><input type="checkbox" id="fAbertas" checked> Só abertas</label>
  </div>

  <div id="tarefasConteudo" aria-live="polite"></div>

  <div class="gaveta-fundo" id="gavetaFundo" hidden></div>
  <aside class="gaveta" id="gaveta" hidden aria-label="Detalhes da tarefa"></aside>
</div>

  <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
</section>
