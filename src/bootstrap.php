<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Clientes.php';
require_once __DIR__ . '/Listas.php';
require_once __DIR__ . '/Tarefas.php';
require_once __DIR__ . '/Automacoes.php';
require_once __DIR__ . '/Rotina.php';
require_once __DIR__ . '/Importacao.php';
require_once __DIR__ . '/Nucleo.php';
require_once __DIR__ . '/App.php';

function rotina_config(string $chave = ''): mixed
{
    static $cfg = null;
    $cfg ??= require __DIR__ . '/config.php';

    return $chave === '' ? $cfg : ($cfg[$chave] ?? null);
}

date_default_timezone_set((string) rotina_config('timezone'));
