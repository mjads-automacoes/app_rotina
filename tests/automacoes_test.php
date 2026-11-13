<?php

declare(strict_types=1);

// Automacoes internas: agenda (rotina diaria), gatilhos de evento, prazo vencido, validacao e protecao contra laco.

t('segunda 06h: a rotina diaria cria uma analise por cliente ativo agendado, com turno e responsavel do cliente', function (): void {
    [$n, , $ana, $beto, $ids] = cenario_auto();
    $r = $n->automacoes->tick(new DateTimeImmutable(SEGUNDA_6H));
    $analises = tarefas_de_origem($n, "t.cliente_id IS NOT NULL");
    eq(['Analisar BILL BORRACHA', 'Analisar LAVANDERIA'], array_column($analises, 'titulo'), 'so ativos agendados para segunda');
    eq(['manha', 'tarde'], array_column($analises, 'turno'), 'turno vem do cliente');
    eq([$ana['id'], $ana['id']], array_map('intval', array_column($analises, 'responsavel_id')), 'responsavel = gestor do cliente');
    eq(['2026-11-09', '2026-11-09'], array_column($analises, 'prazo'));
    eq([1, 1], array_map('intval', array_column($analises, 'lista_id')));
    ok($r['tarefas_criadas'] >= 2);
});

t('o checklist do turno nasce para cada usuario ativo (admin e gestores), sem cliente', function (): void {
    [$n, $admin, $ana, $beto] = cenario_auto();
    $n->automacoes->tick(new DateTimeImmutable(SEGUNDA_6H));
    $fixas = tarefas_de_origem($n, 't.cliente_id IS NULL');
    eq((4 + 3 + 2) * 3, count($fixas), '9 itens por usuario x 3 usuarios');
    $manhaAna = array_filter($fixas, fn ($t) => (int) $t['responsavel_id'] === $ana['id'] && $t['turno'] === 'manha');
    eq(['Atenção nos grupos', 'Verificar contas e saldo', 'Organizar prioridades do dia', 'Executar otimizações, testes e publicações'], array_column(array_values($manhaAna), 'titulo'));
    $n->auth->definirAtivo($beto['id'], false);
    $n->automacoes->tick(new DateTimeImmutable('2026-11-10 06:00:00'));
    $deBeto = array_filter(tarefas_de_origem($n, "t.prazo = '2026-11-10'"), fn ($t) => (int) $t['responsavel_id'] === $beto['id'] && $t['cliente_id'] === null);
    eq(0, count($deBeto), 'usuario desativado nao recebe checklist');
});

t('a agenda respeita o horario, roda uma vez por dia e nao roda no fim de semana', function (): void {
    [$n] = cenario_auto();
    $n->automacoes->tick(new DateTimeImmutable('2026-11-09 04:59:00'));
    eq(0, count(tarefas_de_origem($n)), 'antes das 05:00 nada');
    $n->automacoes->tick(new DateTimeImmutable('2026-11-09 05:00:00'));
    $depois = count(tarefas_de_origem($n));
    ok($depois > 0, 'as 05:00 roda');
    $r = $n->automacoes->tick(new DateTimeImmutable('2026-11-09 18:30:00'));
    eq($depois, count(tarefas_de_origem($n)), 'segunda rodada no mesmo dia nao duplica');
    eq(0, $r['tarefas_criadas']);
    $n->automacoes->tick(new DateTimeImmutable('2026-11-14 08:00:00'));
    eq($depois, count(tarefas_de_origem($n)), 'sabado nao gera rotina');
});

t('mesmo se a execucao for registrada de novo, a chave de origem impede tarefa duplicada', function (): void {
    [$n, $admin] = cenario_auto();
    $n->automacoes->tick(new DateTimeImmutable(SEGUNDA_6H));
    $antes = count(tarefas_de_origem($n));
    $n->pdo->exec('DELETE FROM automacao_execucoes');
    $n->automacoes->tick(new DateTimeImmutable(SEGUNDA_6H));
    eq($antes, count(tarefas_de_origem($n)), 'sem duplicar');
});

t('executar agora gera a rotina do dia sem esperar o horario (e e idempotente)', function (): void {
    [$n, $admin] = cenario_auto();
    $id = (int) $n->pdo->query("SELECT id FROM automacoes WHERE nome = 'Rotina diária dos clientes'")->fetchColumn();
    $r = $n->automacoes->executarAgora($admin, $id, new DateTimeImmutable('2026-11-09 03:00:00'));
    eq(2, $r['tarefas_criadas']);
    eq(0, $n->automacoes->executarAgora($admin, $id, new DateTimeImmutable('2026-11-09 03:10:00'))['tarefas_criadas']);
});

t('gatilho status_mudou: ao concluir tarefa da lista Geral comenta e etiqueta; outra lista nao dispara', function (): void {
    [$n, $admin, $ana] = cenario_auto();
    $n->automacoes->criar($admin, ['nome' => 'Ao concluir', 'gatilho' => 'status_mudou', 'gatilho_config' => ['para_tipo' => 'fechado'], 'condicoes' => ['lista_id' => 2],
        'acoes' => [['tipo' => 'comentar', 'texto' => 'Concluída: {tarefa}'], ['tipo' => 'adicionar_etiqueta', 'nome' => 'feito']]]);
    $a = $n->tarefas->criar($ana, ['titulo' => 'Relatório', 'lista_id' => 2]);
    $b = $n->tarefas->criar($ana, ['titulo' => 'Outra', 'lista_id' => 1]);
    $n->tarefas->moverPorTipo($ana, $a, 'fechado');
    $n->tarefas->moverPorTipo($ana, $b, 'fechado');
    $ta = $n->tarefas->obter($ana, $a);
    $ultimo = array_values(array_filter($ta['atividades'], fn ($x) => $x['tipo'] === 'comentario'))[0];
    eq('comentario', $ultimo['tipo']);
    eq('Concluída: Relatório', $ultimo['texto']);
    eq('Sistema', $ultimo['autor']);
    eq(['feito'], array_column($ta['etiquetas'], 'nome'));
    eq([], $n->tarefas->obter($ana, $b)['etiquetas'], 'lista Rotina nao e afetada');
});

t('gatilho tarefa_criada com condicao de prioridade atribui ao admin', function (): void {
    [$n, $admin, $ana] = cenario_auto();
    $n->automacoes->criar($admin, ['nome' => 'Urgente vai pro admin', 'gatilho' => 'tarefa_criada', 'condicoes' => ['prioridade' => 1],
        'acoes' => [['tipo' => 'atribuir', 'usuario_id' => $admin['id']], ['tipo' => 'adicionar_item', 'texto' => 'Avisar o cliente']]]);
    $u = $n->tarefas->criar($ana, ['titulo' => 'Urgente', 'lista_id' => 2, 'prioridade' => 1]);
    $c = $n->tarefas->criar($ana, ['titulo' => 'Comum', 'lista_id' => 2]);
    $tu = $n->tarefas->obter($admin, $u);
    eq($admin['id'], $tu['responsavel_id']);
    eq(['Avisar o cliente'], array_column($tu['itens'], 'texto'));
    eq($ana['id'], $n->tarefas->obter($ana, $c)['responsavel_id'], 'prioridade normal nao dispara');
});

t('prazo vencido: tarefa aberta ganha prioridade alta e etiqueta uma unica vez; concluida e ignorada', function (): void {
    [$n, , $ana] = cenario_auto();
    $a = $n->tarefas->criar($ana, ['titulo' => 'Atrasada', 'lista_id' => 2, 'prazo' => '2026-11-05']);
    $b = $n->tarefas->criar($ana, ['titulo' => 'Feita', 'lista_id' => 2, 'prazo' => '2026-11-05']);
    $c = $n->tarefas->criar($ana, ['titulo' => 'No prazo', 'lista_id' => 2, 'prazo' => '2026-11-12']);
    $n->tarefas->moverPorTipo($ana, $b, 'fechado');
    $n->automacoes->tick(new DateTimeImmutable(SEGUNDA_6H));
    $ta = $n->tarefas->obter($ana, $a);
    eq(2, $ta['prioridade']);
    eq(['atrasada'], array_column($ta['etiquetas'], 'nome'));
    eq(3, $n->tarefas->obter($ana, $b)['prioridade'], 'concluida nao muda');
    eq(3, $n->tarefas->obter($ana, $c)['prioridade'], 'no prazo nao muda');
    $n->tarefas->atualizar($ana, $a, ['prioridade' => 4]);
    $n->automacoes->tick(new DateTimeImmutable('2026-11-09 09:00:00'));
    eq(4, $n->tarefas->obter($ana, $a)['prioridade'], 'nao dispara de novo para a mesma tarefa');
});

t('criar_tarefa por evento aceita {cliente} e herda o responsavel; laco infinito e contido', function (): void {
    [$n, $admin, $ana, , $ids] = cenario_auto();
    $n->automacoes->criar($admin, ['nome' => 'Onboarding', 'gatilho' => 'tarefa_criada', 'condicoes' => ['lista_id' => 2],
        'acoes' => [['tipo' => 'criar_tarefa', 'lista_id' => 2, 'titulo' => 'Acompanhar {cliente}', 'por' => 'unica', 'responsavel' => 'gatilho', 'prazo' => '+2']]]);
    $n->tarefas->criar($ana, ['titulo' => 'Subir campanha', 'lista_id' => 2, 'cliente_id' => $ids['bill']]);
    $todas = $n->tarefas->listar($admin, []);
    ok(count($todas) >= 2 && count($todas) <= 6, 'gera filhas mas para (total ' . count($todas) . ')');
    $filha = array_values(array_filter($todas, fn ($t) => str_starts_with($t['titulo'], 'Acompanhar')))[0];
    eq('Acompanhar BILL BORRACHA', $filha['titulo']);
    eq($ana['id'], $filha['responsavel_id']);
    ok($filha['prazo'] !== null, 'prazo +2 dias calculado');
});

t('so o administrador gerencia automacoes; validacao dos campos', function (): void {
    [$n, $admin, $ana] = cenario_auto();
    $ok = ['nome' => 'X', 'gatilho' => 'tarefa_criada', 'acoes' => [['tipo' => 'comentar', 'texto' => 'oi']]];
    lanca(fn () => $n->automacoes->criar($ana, $ok), 'administrador');
    lanca(fn () => $n->automacoes->criar($admin, ['nome' => ''] + $ok), 'nome');
    lanca(fn () => $n->automacoes->criar($admin, ['gatilho' => 'chuva'] + $ok), 'gatilho');
    lanca(fn () => $n->automacoes->criar($admin, ['acoes' => []] + $ok), 'ação');
    lanca(fn () => $n->automacoes->criar($admin, ['acoes' => [['tipo' => 'explodir']]] + $ok), 'ação');
    lanca(fn () => $n->automacoes->criar($admin, ['gatilho' => 'agenda', 'gatilho_config' => ['dias' => ['domingo'], 'hora' => '05:00']] + $ok), 'dia');
    lanca(fn () => $n->automacoes->criar($admin, ['gatilho' => 'agenda', 'gatilho_config' => ['dias' => ['segunda'], 'hora' => '25:99']] + $ok), 'hora');
    lanca(fn () => $n->automacoes->criar($admin, ['acoes' => [['tipo' => 'criar_tarefa', 'lista_id' => 99, 'titulo' => 'x', 'por' => 'unica']]] + $ok), 'lista');
    lanca(fn () => $n->automacoes->criar($admin, ['acoes' => [['tipo' => 'definir_prioridade', 'prioridade' => 7]]] + $ok), 'prioridade');
    $id = $n->automacoes->criar($admin, $ok);
    ok($id > 0);
});

t('desligar uma automacao a impede de rodar; editar e excluir', function (): void {
    [$n, $admin, $ana] = cenario_auto();
    $id = $n->automacoes->criar($admin, ['nome' => 'Comenta', 'gatilho' => 'tarefa_criada', 'acoes' => [['tipo' => 'comentar', 'texto' => 'Bem-vinda']]]);
    $t1 = $n->tarefas->criar($ana, ['titulo' => 'A', 'lista_id' => 2]);
    eq('Bem-vinda', $n->tarefas->obter($ana, $t1)['atividades'][1]['texto']);
    $n->automacoes->alternar($admin, $id, false);
    $t2 = $n->tarefas->criar($ana, ['titulo' => 'B', 'lista_id' => 2]);
    eq(1, count($n->tarefas->obter($ana, $t2)['atividades']), 'desligada nao roda');
    $n->automacoes->atualizar($admin, $id, ['nome' => 'Comenta 2', 'gatilho' => 'tarefa_criada', 'acoes' => [['tipo' => 'comentar', 'texto' => 'Oi']], 'ativa' => true]);
    eq('Comenta 2', $n->automacoes->obter($id)['nome']);
    $n->automacoes->excluir($admin, $id);
    eq(null, $n->automacoes->obter($id));
});

t('o historico de execucoes guarda o resumo e a ultima execucao', function (): void {
    [$n] = cenario_auto();
    $n->automacoes->tick(new DateTimeImmutable(SEGUNDA_6H));
    $id = (int) $n->pdo->query("SELECT id FROM automacoes WHERE nome = 'Rotina diária dos clientes'")->fetchColumn();
    $ex = $n->automacoes->execucoes($id);
    eq(1, count($ex));
    ok(str_contains($ex[0]['resumo'], '2'), 'resumo cita as tarefas criadas');
    ok($n->automacoes->obter($id)['ultima_execucao'] !== null);
});

t('o tick preguicoso roda no maximo uma vez por minuto', function (): void {
    [$n] = cenario_auto();
    $r1 = $n->automacoes->tickSeNecessario(new DateTimeImmutable(SEGUNDA_6H));
    ok($r1 !== null, 'primeira vez roda');
    eq(null, $n->automacoes->tickSeNecessario(new DateTimeImmutable('2026-11-09 06:00:30')), 'dentro de 60s nao roda');
    ok($n->automacoes->tickSeNecessario(new DateTimeImmutable('2026-11-09 06:01:05')) !== null, 'depois de 60s roda de novo');
});
