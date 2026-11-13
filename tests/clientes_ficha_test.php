<?php

declare(strict_types=1);

// Ficha completa do cliente e notas.

function cenario_ficha(): array
{
    [$n, $admin, $ana, $beto] = nucleo_teste();

    return [$n->clientes, $ana, $beto, $n];
}

t('ficha completa: empresa, contato, plataformas, orcamento e observacoes', function (): void {
    [$c, $ana] = cenario_ficha();
    $id = $c->criar($ana, ['nome' => 'Bill', 'empresa' => 'Bill Borracha LTDA', 'contato_nome' => 'Bill', 'contato_telefone' => '(11) 91234-5678',
        'contato_email' => 'bill@exemplo.com', 'plataformas' => ['google', 'meta', 'google'], 'orcamento' => 'R$ 3.000/mês', 'observacoes' => "Prefere contato por WhatsApp.\nNão ligar à tarde."]);
    $cli = $c->buscar($ana, $id);
    eq('Bill Borracha LTDA', $cli['empresa']);
    eq('(11) 91234-5678', $cli['contato_telefone']);
    eq(['google', 'meta'], $cli['plataformas'], 'plataformas sem repeticao');
    eq('R$ 3.000/mês', $cli['orcamento']);
    ok(str_contains($cli['observacoes'], 'WhatsApp'));
    lanca(fn () => $c->criar($ana, ['nome' => 'X', 'contato_email' => 'invalido']), 'e-mail');
    lanca(fn () => $c->criar($ana, ['nome' => 'X', 'plataformas' => ['orkut']]), 'plataforma');
    lanca(fn () => $c->criar($ana, ['nome' => 'X', 'observacoes' => str_repeat('a', 2001)]), 'observações');
});

t('notas do cliente: adicionar e ler na ordem, so do dono', function (): void {
    [$c, $ana, $beto] = cenario_ficha();
    $id = $c->criar($ana, ['nome' => 'X']);
    $c->notaAdicionar($ana, $id, 'Reunião marcada para sexta');
    $c->notaAdicionar($ana, $id, 'Cliente pediu relatório');
    $notas = $c->notas($ana, $id);
    eq(['Cliente pediu relatório', 'Reunião marcada para sexta'], array_column($notas, 'texto'), 'mais recente primeiro');
    eq('Ana', $notas[0]['autor']);
    lanca(fn () => $c->notas($beto, $id), 'não encontrado');
    lanca(fn () => $c->notaAdicionar($beto, $id, 'x'), 'não encontrado');
    lanca(fn () => $c->notaAdicionar($ana, $id, '  '), 'nota');
});

t('responsavel do cliente: admin troca para alguem da equipe, gestor nao troca', function (): void {
    [$n, $admin, $ana, $beto] = nucleo_teste();
    $id = $n->clientes->criar($ana, ['nome' => 'X']);
    $n->clientes->atualizar($ana, $id, ['gestor_id' => $beto['id']]);
    eq((int) $ana['id'], $n->clientes->buscar($admin, $id)['gestor_id'], 'gestor nao reatribui');
    $n->clientes->atualizar($admin, $id, ['gestor_id' => $beto['id']]);
    eq((int) $beto['id'], $n->clientes->buscar($admin, $id)['gestor_id'], 'admin reatribui');
    eq('X', $n->clientes->buscar($beto, $id)['nome'], 'o novo responsavel passa a ver o cliente');
    lanca(fn () => $n->clientes->atualizar($admin, $id, ['gestor_id' => 9999]), 'responsável válido');
});
