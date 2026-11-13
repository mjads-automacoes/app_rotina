<?php

declare(strict_types=1);

// Dados de EXEMPLO para o preview local (nao use em producao). Idempotente: se ja existe usuario, nao faz nada.
// php -d extension=pdo_sqlite -d extension=sqlite3 scripts/seed-demo.php

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::padrao();
$n = Nucleo::montar($pdo);

if ($n->auth->total() > 0) {
    echo "Banco ja tem usuarios; nada a fazer.\n";
    exit(0);
}

$admin = $n->auth->buscar($n->auth->criar('Mateus (admin)', 'admin', '1', 'admin'));
$ana = $n->auth->buscar($n->auth->criar('Ana Souza', 'ana', '1', 'gestor'));

Demo::carregar($n, $admin, $ana);

echo "Pronto: login admin (administrador) e login ana (gestora), senha 1\n";
