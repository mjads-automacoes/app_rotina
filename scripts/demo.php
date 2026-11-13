<?php

declare(strict_types=1);

// Dados FICTICIOS na versao online, a pedido do dono. Uso:
//   php scripts/demo.php carregar   cria os dados de exemplo ligados ao primeiro administrador (uma vez so)
//   php scripts/demo.php apagar     remove so os dados de exemplo; o que for real continua
// No Docker/Easypanel roda sozinho na partida quando a variavel ROTINA_DEMO vale "carregar" ou "apagar".

require dirname(__DIR__) . '/src/bootstrap.php';

$acao = $argv[1] ?? '';
$n = Nucleo::montar(Db::padrao());

if ($acao === 'carregar') {
    $id = $n->pdo->query("SELECT id FROM usuarios WHERE papel = 'admin' AND ativo = 1 ORDER BY id LIMIT 1")->fetchColumn();

    if ($id === false) {
        echo "demo: ainda nao ha administrador (faca o Primeiro acesso); nada carregado.\n";
        exit(0);
    }

    echo Demo::carregar($n, $n->auth->buscar((int) $id)) ? "demo: dados de exemplo carregados.\n" : "demo: ja estavam carregados; nada a fazer.\n";
} elseif ($acao === 'apagar') {
    echo 'demo: ' . Demo::apagar($n) . " cliente(s) de exemplo apagado(s), com as tarefas deles.\n";
} else {
    fwrite(STDERR, "uso: php scripts/demo.php carregar|apagar\n");
    exit(1);
}
