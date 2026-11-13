<?php /** @var Request $r @var array $usuario @var array $usuarios */ $csrf = (string) $r->sessao->d['csrf']; $senhaMin = (int) rotina_config('senha_min'); ?>
<h1>Equipe</h1>

<details class="cartao novo-cliente" <?= count($usuarios) <= 1 ? 'open' : '' ?>>
  <summary>+ Novo gestor</summary>
  <form method="post" action="/equipe" class="grade-form">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <label>Nome <input type="text" name="nome" maxlength="120" required></label>
    <label>Login (ou e-mail) <input type="text" name="email" maxlength="120" autocapitalize="none" spellcheck="false" autocomplete="off" required></label>
    <label>Senha inicial<?= $senhaMin > 1 ? ' (mínimo ' . $senhaMin . ')' : '' ?> <input type="password" name="senha" minlength="<?= $senhaMin ?>" autocomplete="new-password" required></label>
    <button class="btn btn-primario" type="submit">Criar gestor</button>
  </form>
</details>

<div class="tabela-rolagem">
  <table class="tabela">
    <thead><tr><th>Nome</th><th>Login</th><th>Papel</th><th>Acesso</th><th>Nova senha</th></tr></thead>
    <tbody>
      <?php foreach ($usuarios as $x): ?>
        <tr>
          <td><?= e((string) $x['nome']) ?></td>
          <td><?= e((string) $x['email']) ?></td>
          <td><?= $x['papel'] === 'admin' ? 'Administrador' : 'Gestor' ?></td>
          <td>
            <?php if ((int) $x['id'] === (int) $usuario['id']): ?>
              <span class="muted">Você</span>
            <?php else: ?>
              <form method="post" action="/equipe/<?= (int) $x['id'] ?>/ativo" class="linha-form">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="ativo" value="<?= (int) $x['ativo'] === 1 ? '0' : '1' ?>">
                <span class="selo selo-<?= (int) $x['ativo'] === 1 ? 'ativo' : 'pausado' ?>"><?= (int) $x['ativo'] === 1 ? 'Ativo' : 'Desativado' ?></span>
                <button class="btn btn-contorno" type="submit"><?= (int) $x['ativo'] === 1 ? 'Desativar' : 'Reativar' ?></button>
              </form>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" action="/equipe/<?= (int) $x['id'] ?>/senha" class="linha-form">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="password" name="senha" minlength="<?= $senhaMin ?>" placeholder="<?= $senhaMin > 1 ? 'mín. ' . $senhaMin : 'nova senha' ?>" autocomplete="new-password" required aria-label="Nova senha de <?= e((string) $x['nome']) ?>">
              <button class="btn btn-contorno" type="submit">Redefinir</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
