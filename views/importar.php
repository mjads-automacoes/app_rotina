<?php
/** @var Request $r @var array $usuario @var bool $conectado @var array $listas @var string $erro @var array $gestores */
$csrf = (string) $r->sessao->d['csrf'];
$admin = $usuario['papel'] === 'admin';
?>
<header class="pagina-topo">
  <div>
    <h1>Importar do ClickUp</h1>
    <p class="muted">O ClickUp serve só para trazer e guardar os dados de cada cliente. Nada é escrito lá: o sistema apenas lê.</p>
  </div>
</header>

<?php if ($erro !== ''): ?><div class="aviso aviso-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>

<div class="ficha-grade">
  <section class="cartao">
    <h2>1. Conexão</h2>
    <?php if ($conectado): ?>
      <p><span class="selo selo-ativo">Conectado</span> O token fica guardado criptografado e nunca é exibido de novo.</p>
      <?php if ($admin): ?>
        <form method="post" action="/importar/remover">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button class="btn btn-contorno" type="submit" data-confirmar="Desconectar o ClickUp?">Desconectar</button>
        </form>
      <?php endif; ?>
    <?php elseif ($admin): ?>
      <p class="muted">No ClickUp: foto do perfil › Configurações › Apps › API Token › Gerar.</p>
      <form method="post" action="/importar/token" class="form-pilha">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>API Token pessoal <input type="password" name="token" placeholder="pk_..." autocomplete="new-password" required></label>
        <button class="btn btn-primario" type="submit">Conectar</button>
      </form>
    <?php else: ?>
      <p class="muted">Peça ao administrador para conectar o ClickUp.</p>
    <?php endif; ?>
  </section>

  <section class="cartao">
    <h2>2. Escolha a lista</h2>
    <?php if (!$conectado): ?>
      <p class="muted">Conecte primeiro.</p>
    <?php elseif ($listas === []): ?>
      <a class="btn btn-contorno" href="/importar?listas=1">Buscar minhas listas</a>
    <?php else: ?>
      <form method="post" action="/importar/executar" class="form-pilha">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Lista de clientes
          <select name="lista_id" required>
            <?php foreach ($listas as $l): ?><option value="<?= e((string) $l['id']) ?>"><?= e((string) $l['caminho']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <?php if ($admin): ?>
          <label>Importar para o gestor
            <select name="gestor_id"><?php foreach ($gestores as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) $g['id'] === (int) $usuario['id'] ? 'selected' : '' ?>><?= e((string) $g['nome']) ?></option><?php endforeach; ?></select>
          </label>
        <?php endif; ?>
        <button class="btn btn-primario" type="submit">Importar clientes</button>
        <p class="muted">Cada tarefa da lista vira um cliente (status “ativo” entra na rotina; “novo” fica de fora). O dia vem da etiqueta ou do vencimento e os comentários viram notas. Importar de novo atualiza sem duplicar.</p>
      </form>
    <?php endif; ?>
  </section>
</div>
