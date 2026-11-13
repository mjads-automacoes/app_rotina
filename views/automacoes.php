<?php /** @var Request $r @var array $usuario */ ?>
<section id="automacoes" data-papel="<?= e((string) $usuario['papel']) ?>">
  <header class="pagina-topo">
    <div>
      <h1>Automações</h1>
      <p class="muted">“Quando acontecer X, faça Y” — rodam dentro do sistema, sem depender de outra ferramenta.</p>
    </div>
    <div class="topo-acoes">
      <?php if ($usuario['papel'] === 'admin'): ?>
        <button class="btn btn-primario" type="button" id="autoNova"><?= icone('mais') ?> Nova automação</button>
      <?php endif; ?>
    </div>
  </header>
  <?php if ($usuario['papel'] !== 'admin'): ?>
    <div class="aviso aviso-info">Você pode ver as automações da equipe. Só o administrador cria e edita.</div>
  <?php endif; ?>

  <div id="autoLista" class="auto-lista" aria-live="polite"></div>

  <div class="gaveta-fundo" id="gavetaFundo" hidden></div>
  <aside class="gaveta gaveta-larga" id="gaveta" hidden aria-label="Editar automação"></aside>
  <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
</section>
