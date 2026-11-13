<?php /** @var Request $r @var string $erro @var array $valores */ $senhaMin = (int) rotina_config('senha_min'); ?>
<form class="cartao form-centro" method="post" action="/setup">
  <h1>Primeiro acesso</h1>
  <p class="muted">Crie a conta do administrador. Depois você cadastra os gestores em Equipe.</p>
  <?php if ($erro !== ''): ?><div class="aviso aviso-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= e((string) $r->sessao->d['csrf']) ?>">
  <label>Nome
    <input type="text" name="nome" value="<?= e((string) ($valores['nome'] ?? '')) ?>" maxlength="120" required autofocus>
  </label>
  <label>Login (ou e-mail)
    <input type="text" name="email" maxlength="120" autocapitalize="none" spellcheck="false" value="<?= e((string) ($valores['email'] ?? '')) ?>" autocomplete="username" required>
  </label>
  <label>Senha<?= $senhaMin > 1 ? ' (mínimo ' . $senhaMin . ' caracteres)' : '' ?>
    <input type="password" name="senha" minlength="<?= $senhaMin ?>" autocomplete="new-password" required>
  </label>
  <button class="btn btn-primario" type="submit">Criar administrador</button>
</form>
