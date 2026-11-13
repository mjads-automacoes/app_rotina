<?php

declare(strict_types=1);

// Dados ficticios (Demo): carregar uma vez so, ligado ao dono; apagar remove so o ficticio.

function contar(Nucleo $n, string $tabela): int
{
    return (int) $n->pdo->query("SELECT COUNT(*) FROM $tabela")->fetchColumn();
}

t('demo: carrega 10 clientes ficticios, todos do dono quando nao ha segundo gestor', function (): void {
    [$n, $admin] = nucleo_teste();
    ok(Demo::carregar($n, $admin));
    eq(10, contar($n, 'clientes'));
    eq(10, (int) $n->pdo->query('SELECT COUNT(*) FROM clientes WHERE gestor_id = ' . (int) $admin['id'])->fetchColumn());
    ok(contar($n, 'tarefas') > 10, 'deveria ter criado a semana de rotina e as tarefas gerais');
    ok(Demo::carregado($n->pdo));
});

t('demo: carregar de novo nao duplica', function (): void {
    [$n, $admin] = nucleo_teste();
    Demo::carregar($n, $admin);
    $tarefas = contar($n, 'tarefas');
    eq(false, Demo::carregar($n, $admin));
    eq(10, contar($n, 'clientes'));
    eq($tarefas, contar($n, 'tarefas'));
});

t('demo: apagar remove so o ficticio e mantem o que e real', function (): void {
    [$n, $admin] = nucleo_teste();
    $real = $n->clientes->criar($admin, ['nome' => 'CLIENTE REAL', 'dias' => ['segunda'], 'turno' => 'manha']);
    $tarefaReal = $n->tarefas->criar($admin, ['titulo' => 'Tarefa real', 'lista_id' => 2]);
    $automacoes = contar($n, 'automacoes');
    Demo::carregar($n, $admin);
    // depois da carga, a rotina gera mais uma tarefa para um cliente ficticio: tambem precisa sumir
    $bill = (int) $n->pdo->query("SELECT id FROM clientes WHERE nome = 'BILL BORRACHA'")->fetchColumn();
    $n->tarefas->criar($admin, ['titulo' => 'Analisar BILL depois', 'lista_id' => 1, 'cliente_id' => $bill]);
    $tarefaRealDepois = $n->tarefas->criar($admin, ['titulo' => 'Outra real', 'lista_id' => 2]);

    eq(10, Demo::apagar($n));
    eq([$real], array_map('intval', $n->pdo->query('SELECT id FROM clientes')->fetchAll(PDO::FETCH_COLUMN)));
    eq([$tarefaReal, $tarefaRealDepois], array_map('intval', $n->pdo->query('SELECT id FROM tarefas ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    eq($automacoes, contar($n, 'automacoes'));
    eq(3, contar($n, 'usuarios'));
    eq(false, Demo::carregado($n->pdo));
    eq(0, Demo::apagar($n), 'apagar sem demo nao faz nada');
});
