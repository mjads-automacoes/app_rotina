<?php

declare(strict_types=1);

// Importacao do ClickUp (somente leitura): lista as listas, traz clientes e comentarios, sem duplicar.

function ms(string $data): string
{
    return (string) ((new DateTimeImmutable($data . ' 12:00:00', new DateTimeZone('UTC')))->getTimestamp() * 1000);
}

function transporte_falso(ArrayObject $chamadas, int $statusLogin = 200): callable
{
    $tarefas = [
        ['id' => 't1', 'name' => 'BILL BORRACHA', 'description' => 'Revisar campanhas e registrar ajustes', 'status' => ['status' => 'CLIENTES ATIVOS'], 'due_date' => ms('2026-11-09'), 'tags' => [],
            'url' => 'https://app.clickup.com/t/t1', 'assignees' => [['username' => 'Maria']], 'custom_fields' => [['name' => 'Orçamento', 'value' => '3000']]],
        ['id' => 't2', 'name' => 'DB CAMARÕES', 'description' => '', 'status' => ['status' => 'CLIENTES ATIVOS'], 'due_date' => ms('2026-11-09'), 'tags' => [['name' => 'quinta'], ['name' => '13h']], 'url' => 'https://app.clickup.com/t/t2', 'assignees' => [], 'custom_fields' => []],
        ['id' => 't3', 'name' => 'CLIENTE NOVO', 'description' => 'Em onboarding', 'status' => ['status' => 'CLIENTE NOVO'], 'due_date' => null, 'tags' => [], 'url' => '', 'assignees' => [], 'custom_fields' => []],
    ];

    return function (string $url, string $token) use ($chamadas, $tarefas, $statusLogin): array {
        $chamadas[] = $url;

        if ($statusLogin !== 200) {
            return ['status' => $statusLogin, 'json' => ['err' => 'Token invalid']];
        }

        $caminho = (string) parse_url($url, PHP_URL_PATH);
        $json = match (true) {
            str_ends_with($caminho, '/team') => ['teams' => [['id' => '900', 'name' => 'Agência']]],
            str_ends_with($caminho, '/team/900/space') => ['spaces' => [['id' => '10', 'name' => 'TRÁFEGO']]],
            str_ends_with($caminho, '/space/10/folder') => ['folders' => [['id' => '30', 'name' => 'Tráfego', 'lists' => [['id' => '559', 'name' => 'Rotina Gestor Teste']]]]],
            str_ends_with($caminho, '/space/10/list') => ['lists' => [['id' => '700', 'name' => 'Sem pasta']]],
            str_ends_with($caminho, '/list/559/task') => ['tasks' => $tarefas, 'last_page' => true],
            str_ends_with($caminho, '/task/t1/comment') => ['comments' => [['id' => 'c1', 'comment_text' => 'Cliente pediu relatório semanal', 'user' => ['username' => 'Maria'], 'date' => ms('2026-11-05')]]],
            str_ends_with($caminho, '/comment') => ['comments' => []],
            default => null,
        };

        return $json === null ? ['status' => 404, 'json' => ['err' => 'nao existe']] : ['status' => 200, 'json' => $json];
    };
}

function cenario_import(int $statusLogin = 200): array
{
    [$n, $admin, $ana, $beto] = nucleo_teste();
    $chamadas = new ArrayObject();
    $imp = new Importacao($n->pdo, $n->clientes, transporte_falso($chamadas, $statusLogin), 'chave-de-teste-123');

    return [$imp, $n, $admin, $ana, $beto, $chamadas];
}

t('o token fica criptografado e nunca volta para a tela', function (): void {
    [$imp, $n] = cenario_import();
    ok(!$imp->temToken());
    $imp->salvarToken('pk_segredo_123');
    ok($imp->temToken());
    $bruto = (string) $n->pdo->query("SELECT valor FROM configuracoes WHERE chave = 'clickup_token'")->fetchColumn();
    ok($bruto !== '' && !str_contains($bruto, 'pk_segredo_123'), 'nao fica em texto puro');
    $imp->removerToken();
    ok(!$imp->temToken());
    lanca(fn () => $imp->salvarToken('  '), 'token');
});

t('sem token ou com token recusado a importacao explica o problema', function (): void {
    [$imp] = cenario_import();
    lanca(fn () => $imp->listas(), 'token');
    [$ruim] = cenario_import(401);
    $ruim->salvarToken('pk_errado');
    lanca(fn () => $ruim->listas(), 'recusou');
});

t('lista as listas do ClickUp com o caminho completo', function (): void {
    [$imp] = cenario_import();
    $imp->salvarToken('pk_x');
    $l = $imp->listas();
    eq(['559', '700'], array_column($l, 'id'));
    eq('TRÁFEGO / Tráfego / Rotina Gestor Teste', $l[0]['caminho']);
    eq('TRÁFEGO / Sem pasta', $l[1]['caminho']);
});

t('importa os clientes: status, dia (etiqueta ou vencimento), turno e dados do ClickUp', function (): void {
    [$imp, $n, , $ana] = cenario_import();
    $imp->salvarToken('pk_x');
    $r = $imp->importar($ana, '559');
    eq(['criados' => 3, 'atualizados' => 0, 'notas' => 1], $r);
    $por = array_column($n->clientes->listar($ana), null, 'nome');
    eq('ativo', $por['BILL BORRACHA']['status']);
    eq(['segunda'], $por['BILL BORRACHA']['dias'], 'dia vem do vencimento (segunda)');
    eq('manha', $por['BILL BORRACHA']['turno']);
    eq(['quinta'], $por['DB CAMARÕES']['dias'], 'etiqueta de dia tem prioridade');
    eq('tarde', $por['DB CAMARÕES']['turno'], 'etiqueta 13h = tarde');
    eq('novo', $por['CLIENTE NOVO']['status'], 'CLIENTE NOVO nao entra na rotina');
    eq('Revisar campanhas e registrar ajustes', $por['BILL BORRACHA']['descricao']);
    ok(str_contains($por['BILL BORRACHA']['observacoes'], 'Orçamento: 3000'), 'campos personalizados nas observacoes');
    eq('t1', $por['BILL BORRACHA']['clickup_id']);
    $dados = json_decode((string) $por['BILL BORRACHA']['clickup_dados'], true);
    eq('https://app.clickup.com/t/t1', $dados['url']);
    eq('CLIENTES ATIVOS', $dados['status']);
    eq(['Cliente pediu relatório semanal'], array_column($n->clientes->notas($ana, $por['BILL BORRACHA']['id']), 'texto'));
});

t('importar de novo atualiza sem duplicar clientes nem comentarios', function (): void {
    [$imp, $n, , $ana] = cenario_import();
    $imp->salvarToken('pk_x');
    $imp->importar($ana, '559');
    $r = $imp->importar($ana, '559');
    eq(['criados' => 0, 'atualizados' => 3, 'notas' => 0], $r);
    eq(3, count($n->clientes->listar($ana)));
    $id = array_column($n->clientes->listar($ana), 'id', 'nome')['BILL BORRACHA'];
    eq(1, count($n->clientes->notas($ana, $id)));
});

t('cada gestor importa para si; admin pode importar para um gestor', function (): void {
    [$imp, $n, $admin, $ana, $beto] = cenario_import();
    $imp->salvarToken('pk_x');
    $imp->importar($ana, '559');
    eq(0, count($n->clientes->listar($beto)));
    $imp->importar($admin, '559', ['gestor_id' => $beto['id']]);
    eq(3, count($n->clientes->listar($beto)), 'admin importou para o Beto');
    eq(3, count($n->clientes->listar($ana)), 'a Ana segue com os dela');
});

t('a importacao so faz leituras em enderecos do ClickUp e recusa id de lista suspeito', function (): void {
    [$imp, , , $ana, , $chamadas] = cenario_import();
    $imp->salvarToken('pk_x');
    $imp->importar($ana, '559');
    ok(count($chamadas) > 0);

    foreach ($chamadas as $url) {
        ok(str_starts_with($url, 'https://api.clickup.com/api/v2/'), 'endereco inesperado: ' . $url);
    }

    lanca(fn () => $imp->importar($ana, '../../etc'), 'lista');
});
