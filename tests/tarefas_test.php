<?php

declare(strict_types=1);

// Tarefas estilo ClickUp: listas e status, criar/editar/mover, checklist, comentarios, etiquetas, filtros e permissoes.

t('o banco ja nasce com duas listas, status e as automacoes padrao', function (): void {
    [$n] = nucleo_teste();
    $listas = $n->listas->listar();
    eq(['Rotina diária', 'Geral'], array_column($listas, 'nome'));
    eq(['A fazer', 'Em andamento', 'Concluído'], array_column($listas[0]['status'], 'nome'));
    eq(['aberto', 'andamento', 'andamento', 'fechado'], array_column($listas[1]['status'], 'tipo'));
    ok(count($n->automacoes->listar()) >= 4, 'automacoes padrao existem');
});

t('admin cria lista com status proprios; gestor nao', function (): void {
    [$n, $admin, $ana] = nucleo_teste();
    $id = $n->listas->criar($admin, 'Onboarding', [['nome' => 'Novo', 'tipo' => 'aberto'], ['nome' => 'Fazendo', 'tipo' => 'andamento'], ['nome' => 'Pronto', 'tipo' => 'fechado']]);
    $l = $n->listas->obter($id);
    eq(['Novo', 'Fazendo', 'Pronto'], array_column($l['status'], 'nome'));
    lanca(fn () => $n->listas->criar($ana, 'X', [['nome' => 'A', 'tipo' => 'aberto'], ['nome' => 'B', 'tipo' => 'fechado']]), 'administrador');
    lanca(fn () => $n->listas->criar($admin, 'Sem fechado', [['nome' => 'A', 'tipo' => 'aberto']]), 'fechado');
    lanca(fn () => $n->listas->criar($admin, '', [['nome' => 'A', 'tipo' => 'aberto'], ['nome' => 'B', 'tipo' => 'fechado']]), 'nome');
});

t('cria tarefa com padroes: primeira coluna, prioridade normal e responsavel = quem criou', function (): void {
    [$n, , $ana] = nucleo_teste();
    $id = $n->tarefas->criar($ana, ['titulo' => '  Revisar campanha  ', 'lista_id' => 2]);
    $t = $n->tarefas->obter($ana, $id);
    eq('Revisar campanha', $t['titulo']);
    eq('A fazer', $t['status_nome']);
    eq('aberto', $t['status_tipo']);
    eq(3, $t['prioridade']);
    eq($ana['id'], $t['responsavel_id']);
    eq('criada', $t['atividades'][0]['tipo']);
    eq(null, $t['concluida_em']);
});

t('valida titulo, prioridade, prazo, turno, lista e etiquetas', function (): void {
    [$n, , $ana] = nucleo_teste();
    lanca(fn () => $n->tarefas->criar($ana, ['titulo' => '', 'lista_id' => 2]), 'título');
    lanca(fn () => $n->tarefas->criar($ana, ['titulo' => 'X', 'lista_id' => 2, 'prioridade' => 9]), 'prioridade');
    lanca(fn () => $n->tarefas->criar($ana, ['titulo' => 'X', 'lista_id' => 2, 'prazo' => '12/11/2026']), 'prazo');
    lanca(fn () => $n->tarefas->criar($ana, ['titulo' => 'X', 'lista_id' => 2, 'turno' => 'noite']), 'turno');
    lanca(fn () => $n->tarefas->criar($ana, ['titulo' => 'X', 'lista_id' => 99]), 'lista');
    lanca(fn () => $n->tarefas->criar($ana, ['titulo' => str_repeat('a', 201), 'lista_id' => 2]), 'título');
});

t('mover entre status: concluir grava a data e reabrir limpa; status de outra lista e recusado', function (): void {
    [$n, , $ana] = nucleo_teste();
    $id = $n->tarefas->criar($ana, ['titulo' => 'X', 'lista_id' => 2]);
    $ls = $n->listas->obter(2)['status'];
    $n->tarefas->mover($ana, $id, (int) $ls[1]['id']);
    eq('Em andamento', $n->tarefas->obter($ana, $id)['status_nome']);
    $n->tarefas->mover($ana, $id, (int) $ls[3]['id']);
    $t = $n->tarefas->obter($ana, $id);
    ok($t['concluida_em'] !== null, 'concluida_em preenchida');
    eq('status', $t['atividades'][array_key_last($t['atividades'])]['tipo']);
    $n->tarefas->mover($ana, $id, (int) $ls[0]['id']);
    eq(null, $n->tarefas->obter($ana, $id)['concluida_em'], 'reabrir limpa a data');
    $outra = $n->listas->obter(1)['status'][0]['id'];
    lanca(fn () => $n->tarefas->mover($ana, $id, (int) $outra), 'status');
});

t('mover por tipo escolhe o primeiro status daquele tipo da lista', function (): void {
    [$n, , $ana] = nucleo_teste();
    $id = $n->tarefas->criar($ana, ['titulo' => 'X', 'lista_id' => 2]);
    $n->tarefas->moverPorTipo($ana, $id, 'fechado');
    eq('Concluído', $n->tarefas->obter($ana, $id)['status_nome']);
    $n->tarefas->moverPorTipo($ana, $id, 'andamento');
    eq('Em andamento', $n->tarefas->obter($ana, $id)['status_nome']);
});

t('editar campos registra atividade apenas do que mudou', function (): void {
    [$n, , $ana, $beto] = nucleo_teste();
    $id = $n->tarefas->criar($ana, ['titulo' => 'X', 'lista_id' => 2, 'prioridade' => 3]);
    $n->tarefas->atualizar($ana, $id, ['titulo' => 'X', 'prioridade' => 1, 'prazo' => '2026-11-20', 'responsavel_id' => $beto['id'], 'descricao' => 'Detalhes']);
    $t = $n->tarefas->obter($ana, $id);
    eq(1, $t['prioridade']);
    eq('2026-11-20', $t['prazo']);
    eq($beto['id'], $t['responsavel_id']);
    $tipos = array_column($t['atividades'], 'tipo');
    ok(in_array('prioridade', $tipos, true) && in_array('prazo', $tipos, true) && in_array('responsavel', $tipos, true), 'atividades de prioridade, prazo e responsavel');
    ok(!in_array('titulo', $tipos, true), 'titulo nao mudou, sem atividade');
    $antes = count($t['atividades']);
    $n->tarefas->atualizar($beto, $id, ['prioridade' => 1]);
    eq($antes, count($n->tarefas->obter($beto, $id)['atividades']), 'sem mudanca = sem atividade nova');
});

t('checklist da tarefa: adicionar, marcar, excluir', function (): void {
    [$n, , $ana] = nucleo_teste();
    $id = $n->tarefas->criar($ana, ['titulo' => 'X', 'lista_id' => 2]);
    $a = $n->tarefas->itemAdicionar($ana, $id, 'Conferir orçamento');
    $b = $n->tarefas->itemAdicionar($ana, $id, 'Pausar anúncios');
    $n->tarefas->itemAlternar($ana, $a, true);
    $t = $n->tarefas->obter($ana, $id);
    eq(['Conferir orçamento', 'Pausar anúncios'], array_column($t['itens'], 'texto'));
    eq([true, false], array_column($t['itens'], 'feito'));
    $n->tarefas->itemExcluir($ana, $b);
    eq(1, count($n->tarefas->obter($ana, $id)['itens']));
    lanca(fn () => $n->tarefas->itemAdicionar($ana, $id, ''), 'texto');
});

t('comentarios entram na linha do tempo com o autor', function (): void {
    [$n, , $ana] = nucleo_teste();
    $id = $n->tarefas->criar($ana, ['titulo' => 'X', 'lista_id' => 2]);
    $n->tarefas->comentar($ana, $id, 'Cliente aprovou o orçamento');
    $t = $n->tarefas->obter($ana, $id);
    $ultimo = $t['atividades'][array_key_last($t['atividades'])];
    eq('comentario', $ultimo['tipo']);
    eq('Cliente aprovou o orçamento', $ultimo['texto']);
    eq('Ana', $ultimo['autor']);
    lanca(fn () => $n->tarefas->comentar($ana, $id, '   '), 'comentário');
});

t('etiquetas sao criadas por nome e reaproveitadas', function (): void {
    [$n, , $ana] = nucleo_teste();
    $a = $n->tarefas->criar($ana, ['titulo' => 'A', 'lista_id' => 2, 'etiquetas' => ['Urgente', 'meta ads']]);
    $b = $n->tarefas->criar($ana, ['titulo' => 'B', 'lista_id' => 2, 'etiquetas' => ['urgente']]);
    eq(['Urgente', 'meta ads'], array_column($n->tarefas->obter($ana, $a)['etiquetas'], 'nome'));
    eq(1, count($n->tarefas->obter($ana, $b)['etiquetas']));
    eq(2, count($n->tarefas->etiquetas()), 'nao duplica "urgente" e "Urgente"');
    $n->tarefas->atualizar($ana, $a, ['etiquetas' => []]);
    eq([], $n->tarefas->obter($ana, $a)['etiquetas']);
});

t('gestor so ve tarefas dele (responsavel, criador ou cliente dele); admin ve todas', function (): void {
    [$n, $admin, $ana, $beto] = nucleo_teste();
    $cBeto = $n->clientes->criar($beto, ['nome' => 'Cliente do Beto']);
    $t1 = $n->tarefas->criar($ana, ['titulo' => 'Da Ana', 'lista_id' => 2]);
    $t2 = $n->tarefas->criar($beto, ['titulo' => 'Do Beto', 'lista_id' => 2]);
    $t3 = $n->tarefas->criar($admin, ['titulo' => 'Do cliente do Beto', 'lista_id' => 2, 'cliente_id' => $cBeto, 'responsavel_id' => $admin['id']]);
    eq(['Da Ana'], array_column($n->tarefas->listar($ana, []), 'titulo'));
    eq(['Do Beto', 'Do cliente do Beto'], array_column($n->tarefas->listar($beto, []), 'titulo'));
    eq(3, count($n->tarefas->listar($admin, [])));
    lanca(fn () => $n->tarefas->obter($ana, $t2), 'não encontrada');
    lanca(fn () => $n->tarefas->atualizar($ana, $t2, ['titulo' => 'Invadida']), 'não encontrada');
    lanca(fn () => $n->tarefas->mover($ana, $t2, 1), 'não encontrada');
    lanca(fn () => $n->tarefas->excluir($ana, $t2), 'não encontrada');
    lanca(fn () => $n->tarefas->criar($ana, ['titulo' => 'X', 'lista_id' => 2, 'cliente_id' => $cBeto]), 'cliente');
});

t('filtros: lista, status, cliente, responsavel, prioridade, etiqueta, busca, prazo e concluidas', function (): void {
    [$n, $admin, $ana, $beto] = nucleo_teste();
    $c = $n->clientes->criar($ana, ['nome' => 'BILL BORRACHA']);
    $a = $n->tarefas->criar($ana, ['titulo' => 'Revisar campanha do Bill', 'lista_id' => 2, 'cliente_id' => $c, 'prioridade' => 1, 'prazo' => '2026-11-10', 'etiquetas' => ['meta']]);
    $b = $n->tarefas->criar($ana, ['titulo' => 'Relatório mensal', 'lista_id' => 1, 'prazo' => '2026-11-20']);
    $d = $n->tarefas->criar($beto, ['titulo' => 'Outra coisa', 'lista_id' => 2]);
    $n->tarefas->moverPorTipo($ana, $b, 'fechado');
    $titulos = static fn (array $f, ?array $u = null): array => array_column($n->tarefas->listar($u ?? $admin, $f), 'titulo');
    eq(['Revisar campanha do Bill'], $titulos(['cliente_id' => $c]));
    eq(['Revisar campanha do Bill'], $titulos(['prioridade' => 1]));
    eq(['Revisar campanha do Bill'], $titulos(['etiqueta' => 'meta']));
    eq(['Revisar campanha do Bill'], $titulos(['busca' => 'bill']));
    eq(['Outra coisa'], $titulos(['responsavel_id' => $beto['id']]));
    eq(['Relatório mensal'], $titulos(['lista_id' => 1]));
    eq(['Revisar campanha do Bill'], $titulos(['ate' => '2026-11-15', 'de' => '2026-11-01']));
    eq(['Revisar campanha do Bill', 'Outra coisa'], $titulos(['abertas' => true]), 'abertas = sem as concluidas');
    eq(['Relatório mensal'], $titulos(['concluidas' => true]));
    eq(1, count($n->tarefas->listar($admin, ['status_id' => $n->listas->obter(1)['status'][2]['id']])));
});

t('excluir tarefa remove checklist, comentarios e etiquetas', function (): void {
    [$n, , $ana] = nucleo_teste();
    $id = $n->tarefas->criar($ana, ['titulo' => 'X', 'lista_id' => 2, 'etiquetas' => ['a']]);
    $n->tarefas->itemAdicionar($ana, $id, 'item');
    $n->tarefas->comentar($ana, $id, 'oi');
    $n->tarefas->excluir($ana, $id);
    foreach (['tarefa_itens', 'tarefa_atividades', 'tarefa_etiquetas'] as $tabela) {
        eq(0, (int) $n->pdo->query("SELECT COUNT(*) FROM {$tabela}")->fetchColumn(), $tabela);
    }
});

t('apagar um cliente mantem a tarefa, sem cliente', function (): void {
    [$n, , $ana] = nucleo_teste();
    $c = $n->clientes->criar($ana, ['nome' => 'X']);
    $id = $n->tarefas->criar($ana, ['titulo' => 'T', 'lista_id' => 2, 'cliente_id' => $c]);
    $n->clientes->excluir($ana, $c);
    eq(null, $n->tarefas->obter($ana, $id)['cliente_id']);
});
