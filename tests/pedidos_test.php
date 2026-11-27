<?php

declare(strict_types=1);

// Aba Agentes: pedidos aos agentes, com status, perguntas, entregas e aprovacao por quem pediu.

t('pedidos: o formulario vem da especificacao, com o "Outro" compartilhado entre os agentes', function (): void {
    [$n] = nucleo_teste();
    $e = $n->pedidos->esquema();
    eq(['gestor_trafego', 'criativos', 'dev_sites', 'social_media'], array_keys($e['agents']));
    eq($e['agents']['gestor_trafego']['forms']['outro']['fields'], $e['agents']['social_media']['forms']['outro']['fields']);
});

t('pedidos: criar valida cliente, prazo, urgencia e campos obrigatorios; quem pede aprova', function (): void {
    [$n, , $ana, $beto] = nucleo_teste();
    $cli = $n->clientes->criar($ana, ['nome' => 'OFICINA']);
    $campos = $n->pedidos->esquema()['agents']['criativos']['forms']['imagem']['fields'];
    $valores = [];
    foreach ($campos as $c) {
        if (!empty($c['required']) && !isset($c['required_if'])) {
            $valores[$c['id']] = isset($c['allow_none_option']) ? ['nenhum' => 'x'] : ($c['type'] === 'offer' ? ['sem_oferta' => true] : 'ok');
        }
    }
    $base = ['agente' => 'criativos', 'tipo' => 'imagem', 'cliente_id' => $cli, 'prioridade' => 'normal', 'prazo' => date('Y-m-d', strtotime('+2 days')), 'campos' => $valores];
    $id = $n->pedidos->criar($ana, $base);
    $p = $n->pedidos->obter($ana, $id);
    eq(['a_fazer', (int) $ana['id'], (int) $ana['id'], 'OFICINA'], [$p['status'], (int) $p['solicitante_id'], (int) $p['aprovador_id'], $p['cliente_nome']]);
    eq('PED-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT), $p['codigo']);
    lanca(fn () => $n->pedidos->criar($ana, ['prazo' => date('Y-m-d', strtotime('-1 day'))] + $base), 'prazo');
    lanca(fn () => $n->pedidos->criar($ana, ['prioridade' => 'urgente'] + $base), 'motivo');
    lanca(fn () => $n->pedidos->criar($ana, ['campos' => []] + $base), 'Preencha');
    lanca(fn () => $n->pedidos->criar($beto, $base), 'não encontrado'); // cliente de outro gestor
    $comOferta = $base;
    $comOferta['campos'][array_values(array_filter($campos, fn ($c) => $c['type'] === 'offer'))[0]['id']] = ['texto' => 'R$ 99', 'validade' => date('Y-m-d', strtotime('-1 day'))];
    lanca(fn () => $n->pedidos->criar($ana, $comOferta), 'validade');
    eq(0, count($n->pedidos->listar($beto)), 'outro gestor nao ve');
    eq(1, count($n->pedidos->listar($ana)));
});

t('pedidos: agente pergunta, solicitante responde, agente entrega (v1), ajuste, v2 e aprovado', function (): void {
    [$n, , $ana, $beto] = nucleo_teste();
    $cli = $n->clientes->criar($ana, ['nome' => 'OFICINA']);
    $campos = $n->pedidos->esquema()['agents']['gestor_trafego']['forms']['outro']['fields'];
    $valores = [];
    foreach ($campos as $c) {
        if (!empty($c['required']) && !isset($c['required_if'])) {
            $valores[$c['id']] = isset($c['allow_none_option']) ? ['nenhum' => 'x'] : 'ok';
        }
    }
    $id = $n->pedidos->criar($ana, ['agente' => 'social_media', 'tipo' => 'outro', 'cliente_id' => $cli, 'prazo' => date('Y-m-d'), 'campos' => $valores]);
    $n->pedidos->agenteStatus($id, 'fazendo', 'Agente Social');
    $n->pedidos->agentePerguntar($id, 'Qual o preço da revisão?', 'Agente Social');
    eq('falta_informacao', $n->pedidos->obter($ana, $id)['status']);
    lanca(fn () => $n->pedidos->decidir($ana, $id, 'aprovar'), 'entrega');
    $n->pedidos->responder($ana, $id, 'R$ 99');
    eq('fazendo', $n->pedidos->obter($ana, $id)['status']);
    eq(1, $n->pedidos->agenteEntregar($id, ['resumo' => 'Arte pronta', 'arquivos' => [['nome' => 'arte.png', 'url' => 'https://x.com/a.png'], 'javascript:alert(1)'], 'checklist' => ['Preço na arte']], 'Agente Social'));
    lanca(fn () => $n->pedidos->decidir($beto, $id, 'aprovar'), 'não encontrado');
    lanca(fn () => $n->pedidos->decidir($ana, $id, 'ajuste'), 'ajuste');
    $n->pedidos->decidir($ana, $id, 'ajuste', 'Aumentar a logo');
    eq('ajuste', $n->pedidos->obter($ana, $id)['status']);
    eq(2, $n->pedidos->agenteEntregar($id, ['resumo' => 'Logo maior'], 'Agente Social'));
    $n->pedidos->decidir($ana, $id, 'aprovar');
    $p = $n->pedidos->obter($ana, $id);
    eq('concluido', $p['status']);
    eq(['criado', 'status', 'pergunta', 'resposta', 'entrega', 'ajuste', 'entrega', 'aprovado'], array_column($p['eventos'], 'tipo'));
    eq([['nome' => 'arte.png', 'url' => 'https://x.com/a.png']], $p['eventos'][4]['dados']['arquivos'], 'so links http(s)');
    lanca(fn () => $n->pedidos->decidir($ana, $id, 'cancelar', 'x'), 'encerrado');
});
