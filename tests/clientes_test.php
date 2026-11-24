<?php

declare(strict_types=1);

// Clientes: cada gestor ve e mexe so nos seus; admin ve todos.

function cenario_clientes(): array
{
    $pdo = banco_teste();
    $auth = new Auth($pdo);
    $admin = $auth->buscar($auth->criar('Admin', 'admin@exemplo.com', 'senhaforte1', 'admin'));
    $ana = $auth->buscar($auth->criar('Ana', 'ana@exemplo.com', 'senhaforte1', 'gestor'));
    $beto = $auth->buscar($auth->criar('Beto', 'beto@exemplo.com', 'senhaforte1', 'gestor'));

    return [new Clientes($pdo), $admin, $ana, $beto];
}

t('cria cliente do proprio gestor com dias e turno', function (): void {
    [$c, , $ana] = cenario_clientes();
    $id = $c->criar($ana, ['nome' => '  Bill Borracha ', 'descricao' => 'Revisar campanhas', 'dias' => ['segunda', 'quarta'], 'turno' => 'tarde']);
    $cli = $c->buscar($ana, $id);
    eq('Bill Borracha', $cli['nome'], 'nome sem espacos nas pontas');
    eq(['segunda', 'quarta'], $cli['dias']);
    eq('tarde', $cli['turno']);
    eq('ativo', $cli['status'], 'status padrao');
    eq($ana['id'], $cli['gestor_id']);
});

t('valida nome, dias, turno e status', function (): void {
    [$c, , $ana] = cenario_clientes();
    lanca(fn () => $c->criar($ana, ['nome' => '']), 'nome');
    lanca(fn () => $c->criar($ana, ['nome' => 'X', 'dias' => ['domingo']]), 'dia');
    lanca(fn () => $c->criar($ana, ['nome' => 'X', 'turno' => 'noite']), 'turno');
    lanca(fn () => $c->criar($ana, ['nome' => 'X', 'status' => 'ok']), 'status');
    lanca(fn () => $c->criar($ana, ['nome' => 'X', 'link' => 'javascript:alert(1)']), 'link');
});

t('dias repetidos e fora de ordem viram lista unica na ordem da semana', function (): void {
    [$c, , $ana] = cenario_clientes();
    $id = $c->criar($ana, ['nome' => 'X', 'dias' => ['sexta', 'segunda', 'sexta']]);
    eq(['segunda', 'sexta'], $c->buscar($ana, $id)['dias']);
});

t('gestor so lista e abre os proprios clientes', function (): void {
    [$c, , $ana, $beto] = cenario_clientes();
    $a = $c->criar($ana, ['nome' => 'Do Ana']);
    $b = $c->criar($beto, ['nome' => 'Do Beto']);
    eq(['Do Ana'], array_column($c->listar($ana), 'nome'));
    eq(['Do Beto'], array_column($c->listar($beto), 'nome'));
    lanca(fn () => $c->buscar($ana, $b), 'não encontrado');
    lanca(fn () => $c->atualizar($ana, $b, ['nome' => 'Invadido']), 'não encontrado');
    lanca(fn () => $c->excluir($ana, $b), 'não encontrado');
    eq('Do Beto', $c->buscar($beto, $b)['nome'], 'nada mudou');
});

t('admin ve todos, filtra por gestor e pode criar para um gestor', function (): void {
    [$c, $admin, $ana, $beto] = cenario_clientes();
    $c->criar($ana, ['nome' => 'A']);
    $c->criar($beto, ['nome' => 'B']);
    $id = $c->criar($admin, ['nome' => 'C', 'gestor_id' => $beto['id']]);
    eq(3, count($c->listar($admin)));
    eq(['B', 'C'], array_column($c->listar($admin, $beto['id']), 'nome'));
    eq($beto['id'], $c->buscar($admin, $id)['gestor_id']);
    lanca(fn () => $c->criar($admin, ['nome' => 'D', 'gestor_id' => 9999]), 'responsável');
});

t('gestor nao consegue criar cliente em nome de outro', function (): void {
    [$c, , $ana, $beto] = cenario_clientes();
    $id = $c->criar($ana, ['nome' => 'X', 'gestor_id' => $beto['id']]);
    eq($ana['id'], $c->buscar($ana, $id)['gestor_id'], 'gestor_id enviado e ignorado');
});

t('atualiza e exclui', function (): void {
    [$c, , $ana] = cenario_clientes();
    $id = $c->criar($ana, ['nome' => 'X', 'dias' => ['segunda']]);
    $c->atualizar($ana, $id, ['nome' => 'Y', 'dias' => ['terca', 'quinta'], 'status' => 'churn', 'link' => 'https://exemplo.com/cliente']);
    $cli = $c->buscar($ana, $id);
    eq('Y', $cli['nome']);
    eq(['terca', 'quinta'], $cli['dias']);
    eq('churn', $cli['status']);
    eq('https://exemplo.com/cliente', $cli['link']);
    $c->excluir($ana, $id);
    eq([], $c->listar($ana));
});

t('ficha: CNPJ (14 numeros, guardado formatado), cidade e estado', function (): void {
    [$n, , $ana] = nucleo_teste();
    $id = $n->clientes->criar($ana, ['nome' => 'X', 'cnpj' => '12345678000199', 'cidade' => 'Santos', 'estado' => 'sp']);
    $c = $n->clientes->buscar($ana, $id);
    eq(['12.345.678/0001-99', 'Santos', 'SP'], [$c['cnpj'], $c['cidade'], $c['estado']]);
    $n->clientes->atualizar($ana, $id, ['nome' => 'X', 'cnpj' => '', 'estado' => 'RJ']);
    $c = $n->clientes->buscar($ana, $id);
    eq(['', 'Santos', 'RJ'], [$c['cnpj'], $c['cidade'], $c['estado']]);
    lanca(fn () => $n->clientes->atualizar($ana, $id, ['nome' => 'X', 'cnpj' => '123']), '14 números');
    lanca(fn () => $n->clientes->atualizar($ana, $id, ['nome' => 'X', 'estado' => 'XX']), 'estado');
});

t('ficha: entregaveis do cliente (varios, so da lista)', function (): void {
    [$n, , $ana] = nucleo_teste();
    $id = $n->clientes->criar($ana, ['nome' => 'X', 'entregaveis' => ['relatorio', 'trafego']]);
    eq(['trafego', 'relatorio'], $n->clientes->buscar($ana, $id)['entregaveis'], 'na ordem da lista');
    $n->clientes->atualizar($ana, $id, ['nome' => 'X', 'cidade' => 'Santos']);
    eq(['trafego', 'relatorio'], $n->clientes->buscar($ana, $id)['entregaveis'], 'salvar outro campo nao apaga');
    $n->clientes->atualizar($ana, $id, ['nome' => 'X', 'entregaveis' => []]);
    eq([], $n->clientes->buscar($ana, $id)['entregaveis']);
    lanca(fn () => $n->clientes->atualizar($ana, $id, ['nome' => 'X', 'entregaveis' => ['inventado']]), 'não é válido');
});
