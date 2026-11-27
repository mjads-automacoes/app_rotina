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

t('ficha: entregavel e uma etiqueta da lista; o admin edita a lista e quem tinha a excluida fica sem', function (): void {
    [$n, $admin, $ana] = nucleo_teste();
    $lista = $n->clientes->entregaveis();
    eq(['Completo', 'Tráfego'], array_slice(array_column($lista, 'nome'), 0, 2), 'lista inicial igual ao ClickUp');
    $id = $n->clientes->criar($ana, ['nome' => 'X', 'entregaveis' => 'trafego']);
    eq('trafego', $n->clientes->buscar($ana, $id)['entregaveis']);
    $n->clientes->atualizar($ana, $id, ['nome' => 'X', 'cidade' => 'Santos']);
    eq('trafego', $n->clientes->buscar($ana, $id)['entregaveis'], 'salvar outro campo nao apaga');
    lanca(fn () => $n->clientes->criar($ana, ['nome' => 'Y', 'entregaveis' => 'inventado']), 'da lista');
    lanca(fn () => $n->clientes->salvarEntregaveis($ana, $lista), 'administrador');
    $nova = $n->clientes->salvarEntregaveis($admin, [['id' => 'trafego', 'nome' => 'Tráfego Pago', 'cor' => '#2ecc71'], ['nome' => 'Novo 🚀', 'cor' => '#f5c400']]);
    eq(['Tráfego Pago', 'Novo 🚀'], array_column($nova, 'nome'), 'renomeia, cria e tira os demais');
    eq('trafego', $nova[0]['id'], 'mantem o id ao renomear');
    eq('trafego', $n->clientes->buscar($ana, $id)['entregaveis']);
    $n->clientes->salvarEntregaveis($admin, [$nova[1]]);
    eq('', $n->clientes->buscar($ana, $id)['entregaveis'], 'etiqueta excluida: cliente fica sem');
    lanca(fn () => $n->clientes->salvarEntregaveis($admin, [['nome' => 'X', 'cor' => '#123456']]), 'cores');
});

t('ficha: dados para os agentes (IDs Meta e Google, WhatsApp, dominio, logo...) validados e salvos', function (): void {
    [$n, , $ana] = nucleo_teste();
    $id = $n->clientes->criar($ana, ['nome' => 'X', 'meta_conta_id' => 'act_123456789', 'google_ads_id' => '123-456-7890', 'whatsapp_comercial' => '(18) 99999-0000', 'dominio' => 'site.com.br', 'logo' => 'https://drive.google.com/x', 'cores' => 'preto e lima']);
    $c = $n->clientes->buscar($ana, $id);
    eq(['act_123456789', '123-456-7890', '(18) 99999-0000', 'site.com.br', 'preto e lima'], [$c['meta_conta_id'], $c['google_ads_id'], $c['whatsapp_comercial'], $c['dominio'], $c['cores']]);
    $n->clientes->atualizar($ana, $id, ['nome' => 'X', 'cidade' => 'Santos']);
    eq('act_123456789', $n->clientes->buscar($ana, $id)['meta_conta_id'], 'salvar outro campo nao apaga');
    lanca(fn () => $n->clientes->atualizar($ana, $id, ['nome' => 'X', 'google_ads_id' => '12']), 'Google Ads');
    lanca(fn () => $n->clientes->atualizar($ana, $id, ['nome' => 'X', 'whatsapp_comercial' => '999']), 'DDD');
    lanca(fn () => $n->clientes->atualizar($ana, $id, ['nome' => 'X', 'logo' => 'javascript:x']), 'logo');
});
