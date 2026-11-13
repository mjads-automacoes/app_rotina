<?php

declare(strict_types=1);

// Roteador do servidor embutido do PHP (so para uso local): serve o arquivo se existir, senao cai no index.php.
$caminho = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$arquivo = __DIR__ . $caminho;

if ($caminho !== '/' && is_file($arquivo) && !str_ends_with($arquivo, '.php')) {
    return false;
}

require __DIR__ . '/index.php';
