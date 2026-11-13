<?php

declare(strict_types=1);

// Executor de testes sem dependencias: php tests/run.php [filtro]
// O PHP deste computador pode vir sem SQLite; nesse caso o executor se reabre com as extensoes ligadas.

if (!extension_loaded('pdo_sqlite') && getenv('ROTINA_TESTS_REEXEC') !== '1') {
    putenv('ROTINA_TESTS_REEXEC=1');
    $cmd = escapeshellarg(PHP_BINARY) . ' -d extension=pdo_sqlite -d extension=sqlite3 ' . escapeshellarg(__FILE__);
    foreach (array_slice($argv, 1) as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    passthru($cmd, $code);
    exit($code);
}

require __DIR__ . '/helpers.php';

$filtro = $argv[1] ?? '';
$arquivos = glob(__DIR__ . '/*_test.php') ?: [];
sort($arquivos);

foreach ($arquivos as $arquivo) {
    if ($filtro !== '' && !str_contains($arquivo, $filtro)) {
        continue;
    }

    echo '== ' . basename($arquivo) . PHP_EOL;
    require $arquivo;
}

exit(t_resumo());
