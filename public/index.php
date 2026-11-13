<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

session_name('rotina_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (bool) rotina_config('cookie_secure'),
]);
session_start();

$sessao = new Sessao(true);
$sessao->d = &$_SESSION;

$cabecalhos = [];

foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $cabecalhos[str_replace('_', '-', substr($k, 5))] = (string) $v;
    }
}

$post = $_POST;

if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $corpo = json_decode((string) file_get_contents('php://input'), true);
    $post = is_array($corpo) ? $corpo : [];
}

$caminho = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$request = new Request(
    strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
    $caminho,
    $_GET,
    $post,
    $cabecalhos,
    (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
    $sessao,
);

try {
    $resposta = (new App(Db::padrao()))->handle($request);
} catch (Throwable $e) {
    error_log('[rotina] falha ao iniciar: ' . $e->getMessage());
    $resposta = new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'Erro ao iniciar o sistema.');
}

http_response_code($resposta->status);

foreach ($resposta->headers as $nome => $valor) {
    header($nome . ': ' . $valor);
}

echo $resposta->body;
