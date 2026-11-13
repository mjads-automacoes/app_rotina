<?php
/** @var Request $r @var ?array $usuario @var ?array $flash @var string $ativo @var string $conteudo @var bool $embutido */
$embutido = $embutido ?? false;
$app = (string) rotina_config('app_name');
$menu = [
    'Trabalho' => [
        'rotina' => ['/', 'Rotina', 'rotina'],
        'clientes' => ['/clientes', 'Clientes', 'clientes'],
    ],
    'Sistema' => [
        'automacoes' => ['/automacoes', 'Automações', 'automacoes'],
        'historico' => ['/historico', 'Histórico', 'historico'],
        'importar' => ['/importar', 'Importar', 'importar'],
    ],
];

if ($usuario !== null && $usuario['papel'] === 'admin') {
    $menu['Sistema']['equipe'] = ['/equipe', 'Equipe', 'equipe'];
}

$ativoMenu = $ativo === 'cliente' ? 'clientes' : $ativo;
$scriptsDaPagina = ['rotina' => ['rotina.js', 'tarefas.js'], 'automacoes' => ['automacoes.js']][$ativo] ?? [];
?><!doctype html>
<html lang="pt-BR" data-tema="escuro">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <meta name="color-scheme" content="dark light">
  <title><?= e($app) ?></title>
  <script src="/assets/theme.js"></script>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="<?= $embutido ? 'embutido' : ($usuario !== null ? 'com-menu' : 'sem-menu') ?>" data-csrf="<?= e((string) $r->sessao->d['csrf']) ?>">
<?php if ($usuario !== null && !$embutido): ?>
  <header class="topo-movel">
    <button class="btn btn-icone" type="button" data-menu-toggle aria-label="Abrir menu"><?= icone('menu') ?></button>
    <span class="marca"><?= e($app) ?></span>
    <button class="btn btn-icone" type="button" data-theme-toggle aria-label="Trocar tema"><span class="so-escuro"><?= icone('sol') ?></span><span class="so-claro"><?= icone('lua') ?></span></button>
  </header>
  <div class="cortina" data-menu-toggle></div>
  <aside class="menu" id="menu">
    <div class="marca"><span class="marca-logo"><?= e(mb_strtoupper(mb_substr($app, 0, 1))) ?></span><span><?= e($app) ?></span></div>
    <nav aria-label="Principal">
      <?php foreach ($menu as $grupo => $itens): ?>
        <p class="menu-grupo"><?= e($grupo) ?></p>
        <?php foreach ($itens as $chave => [$href, $rotulo, $ic]): ?>
          <a href="<?= e($href) ?>" class="<?= $ativoMenu === $chave ? 'ativo' : '' ?>"><?= icone($ic) ?><span><?= e($rotulo) ?></span></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="menu-rodape">
      <a class="usuario <?= $ativoMenu === 'conta' ? 'ativo' : '' ?>" href="/conta" title="Minha conta">
        <span class="avatar"><?= e(mb_strtoupper(mb_substr((string) $usuario['nome'], 0, 2))) ?></span>
        <span class="usuario-nome"><?= e((string) $usuario['nome']) ?><small><?= $usuario['papel'] === 'admin' ? 'Administrador' : 'Gestor' ?></small></span>
      </a>
      <div class="menu-acoes">
        <button class="btn btn-suave btn-icone" type="button" data-theme-toggle title="Trocar tema" aria-label="Trocar tema"><span class="so-escuro"><?= icone('sol') ?> Claro</span><span class="so-claro"><?= icone('lua') ?> Escuro</span></button>
        <form method="post" action="/logout">
          <input type="hidden" name="csrf" value="<?= e((string) $r->sessao->d['csrf']) ?>">
          <button class="btn btn-suave btn-icone" type="submit" title="Sair" aria-label="Sair"><?= icone('sair') ?> Sair</button>
        </form>
      </div>
    </div>
  </aside>
<?php endif; ?>
  <main class="conteudo">
    <?php if ($usuario === null && !$embutido): ?>
      <div class="marca marca-centro"><span class="marca-logo"><?= e(mb_strtoupper(mb_substr($app, 0, 1))) ?></span><span><?= e($app) ?></span></div>
      <div class="login-tema"><button class="btn btn-icone" type="button" data-theme-toggle aria-label="Trocar tema"><span class="so-escuro"><?= icone('sol') ?></span><span class="so-claro"><?= icone('lua') ?></span></button></div>
    <?php endif; ?>
    <?php if ($flash): ?><div class="aviso aviso-<?= $flash['tipo'] === 'ok' ? 'ok' : 'erro' ?>" role="status"><?= e((string) $flash['msg']) ?></div><?php endif; ?>
    <?= $conteudo ?>
  </main>
  <script src="/assets/app.js"></script>
<?php foreach ($scriptsDaPagina as $js): ?>
  <script src="/assets/<?= e($js) ?>"></script>
<?php endforeach; ?>
</body>
</html>
