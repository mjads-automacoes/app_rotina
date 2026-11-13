<?php /** @var string $titulo @var string $msg @var ?array $usuario */ ?>
<div class="cartao form-centro">
  <h1><?= e($titulo) ?></h1>
  <p><?= e($msg) ?></p>
  <a class="btn btn-contorno" href="<?= $usuario !== null ? '/' : '/login' ?>">Voltar</a>
</div>
