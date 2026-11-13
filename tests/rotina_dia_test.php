<?php

declare(strict_types=1);

// Rotina do dia: tarefas com turno e prazo, agrupadas por dia/turno (checklist x clientes), concluir e historico.

function cenario_dia(): array
{
    [$n, $admin, $ana, $beto, $ids] = cenario_auto();
    $n->automacoes->tick(new DateTimeImmutable(SEGUNDA_6H));

    return [$n, $admin, $ana, $beto, $ids];
}

t('a rotina da semana separa checklist (sem cliente) e clientes por turno', function (): void {
    [$n, , $ana] = cenario_dia();
    $d = $n->rotina->dia($ana, '2026-11-09');
    eq(['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'], array_keys($d['semana']));
    $seg = $d['semana']['2026-11-09'];
    eq('segunda', $seg['dia']);
    eq(['manha', 'tarde', 'fechamento'], array_keys($seg['turnos']));
    eq(['Analisar BILL BORRACHA'], array_column($seg['turnos']['manha']['clientes'], 'titulo'));
    eq(['Analisar LAVANDERIA'], array_column($seg['turnos']['tarde']['clientes'], 'titulo'));
    eq(4, count($seg['turnos']['manha']['checklist']));
    eq(2, count($seg['turnos']['fechamento']['checklist']));
    eq(2 + 9, $seg['total'], '2 clientes + 9 itens de checklist');
    eq(0, $seg['feitas']);
    eq([], $d['semana']['2026-11-10']['turnos']['manha']['clientes'], 'terca ainda nao foi gerada');
});

t('o item de cliente traz a descricao e o link do cadastro do cliente', function (): void {
    [$n, , $ana, , $ids] = cenario_dia();
    $n->clientes->atualizar($ana, $ids['bill'], ['descricao' => 'Revisar campanhas', 'link' => 'https://exemplo.com/bill']);
    $item = $n->rotina->dia($ana, '2026-11-09')['semana']['2026-11-09']['turnos']['manha']['clientes'][0];
    eq('Revisar campanhas', $item['cliente_descricao']);
    eq('https://exemplo.com/bill', $item['cliente_link']);
    eq('BILL BORRACHA', $item['cliente_nome']);
    eq('aberto', $item['status_tipo']);
});

t('gestor so ve a propria rotina; admin escolhe o gestor', function (): void {
    [$n, $admin, $ana, $beto] = cenario_dia();
    $titulos = static fn (array $d): array => array_column($d['semana']['2026-11-09']['turnos']['manha']['clientes'], 'titulo');
    eq(['Analisar BILL BORRACHA'], $titulos($n->rotina->dia($ana, '2026-11-09')));
    eq([], $titulos($n->rotina->dia($beto, '2026-11-09')), 'Beto nao tem cliente na segunda');
    eq(['Analisar BILL BORRACHA'], $titulos($n->rotina->dia($ana, '2026-11-09', $beto['id'])), 'gestor ignora o gestor pedido: continua vendo a propria');
    eq(['Analisar BILL BORRACHA'], $titulos($n->rotina->dia($admin, '2026-11-09', $ana['id'])));
});

t('iniciar e concluir andam o status, contam no progresso e a nota vira comentario', function (): void {
    [$n, , $ana] = cenario_dia();
    $item = $n->rotina->dia($ana, '2026-11-09')['semana']['2026-11-09']['turnos']['manha']['clientes'][0];
    $n->rotina->iniciar($ana, $item['id']);
    eq('andamento', $n->rotina->dia($ana, '2026-11-09')['semana']['2026-11-09']['turnos']['manha']['clientes'][0]['status_tipo']);
    $n->rotina->concluir($ana, $item['id'], 'Ajustei o orçamento');
    $d = $n->rotina->dia($ana, '2026-11-09')['semana']['2026-11-09'];
    eq('fechado', $d['turnos']['manha']['clientes'][0]['status_tipo']);
    eq(1, $d['feitas']);
    $t = $n->tarefas->obter($ana, $item['id']);
    eq('Ajustei o orçamento', $t['atividades'][array_key_last($t['atividades'])]['texto']);
    $n->rotina->reabrir($ana, $item['id']);
    eq(0, $n->rotina->dia($ana, '2026-11-09')['semana']['2026-11-09']['feitas']);
});

t('outro gestor nao conclui a tarefa alheia', function (): void {
    [$n, , $ana, $beto] = cenario_dia();
    $item = $n->rotina->dia($ana, '2026-11-09')['semana']['2026-11-09']['turnos']['manha']['clientes'][0];
    lanca(fn () => $n->rotina->concluir($beto, $item['id'], ''), 'não encontrada');
});

t('gerar do dia cria a rotina sob demanda e e idempotente', function (): void {
    [$n, , $ana] = cenario_auto();
    $r = $n->rotina->gerarDoDia(new DateTimeImmutable('2026-11-09 03:00:00'));
    ok($r['tarefas_criadas'] > 0);
    eq(0, $n->rotina->gerarDoDia(new DateTimeImmutable('2026-11-09 03:05:00'))['tarefas_criadas']);
    eq(1, count($n->rotina->dia($ana, '2026-11-09')['semana']['2026-11-09']['turnos']['manha']['clientes']));
});

t('historico traz as tarefas concluidas com o ultimo comentario como nota', function (): void {
    [$n, $admin, $ana, $beto] = cenario_dia();
    $item = $n->rotina->dia($ana, '2026-11-09')['semana']['2026-11-09']['turnos']['manha']['clientes'][0];
    $n->rotina->concluir($ana, $item['id'], 'Pausei 2 anúncios');
    $h = $n->rotina->historico($ana, []);
    eq(1, count($h));
    eq('Analisar BILL BORRACHA', $h[0]['titulo']);
    eq('BILL BORRACHA', $h[0]['cliente_nome']);
    eq('Pausei 2 anúncios', $h[0]['nota']);
    eq(0, count($n->rotina->historico($beto, [])), 'cada gestor ve o seu');
    eq(1, count($n->rotina->historico($admin, [])));
    eq(0, count($n->rotina->historico($ana, ['cliente_id' => 9999])));
});

t('sabado mostra a semana seguinte', function (): void {
    [$n, , $ana] = cenario_dia();
    eq('2026-11-16', array_key_first($n->rotina->dia($ana, '2026-11-14')['semana']));
});
