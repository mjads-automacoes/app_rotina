<?php /** @var Request $r @var array $usuario */ $senhaMin = (int) rotina_config('senha_min'); ?>
<h1>Minha conta</h1>
<div class="cartao form-centro form-esq">
  <p><strong><?= e((string) $usuario['nome']) ?></strong><br><span class="muted"><?= e((string) $usuario['email']) ?> · <?= $usuario['papel'] === 'admin' ? 'Administrador' : 'Gestor' ?></span></p>
  <form method="post" action="/conta/senha">
    <input type="hidden" name="csrf" value="<?= e((string) $r->sessao->d['csrf']) ?>">
    <label>Senha atual <input type="password" name="atual" autocomplete="current-password" required></label>
    <label>Nova senha<?= $senhaMin > 1 ? ' (mínimo ' . $senhaMin . ')' : '' ?> <input type="password" name="nova" minlength="<?= $senhaMin ?>" autocomplete="new-password" required></label>
    <button class="btn btn-primario" type="submit">Trocar senha</button>
  </form>
</div>
