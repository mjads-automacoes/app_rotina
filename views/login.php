<?php /** @var Request $r @var string $erro @var string $email */ ?>
<form class="cartao form-centro" method="post" action="/login">
  <h1>Entrar</h1>
  <?php if ($erro !== ''): ?><div class="aviso aviso-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= e((string) $r->sessao->d['csrf']) ?>">
  <label>Login
    <input type="text" name="email" autocapitalize="none" spellcheck="false" value="<?= e($email) ?>" autocomplete="username" required autofocus>
  </label>
  <label>Senha
    <input type="password" name="senha" autocomplete="current-password" required>
  </label>
  <button class="btn btn-primario" type="submit">Entrar</button>
</form>
