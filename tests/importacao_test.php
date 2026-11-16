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
        ['id' => 't1', 'name' => 'BILL BORRACHA', 'description' => 'Revisar campanhas e registrar ajustes', 'markdown_description' => "# ONBOARDING - BILL\n**Revisar** campanhas e registrar ajustes\n" . str_repeat('x', 3000), 'status' => ['status' => 'CLIENTES ATIVOS'], 'due_date' => ms('2026-11-09'), 'tags' => [],
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
    ok(str_starts_with($por['BILL BORRACHA']['observacoes'], "# ONBOARDING - BILL\n**Revisar**"), 'observacoes = descricao do ClickUp com a formatacao');
    ok(mb_strlen($por['BILL BORRACHA']['observacoes']) > 3000, 'descricao inteira, sem cortar em 2000');
    ok(!str_contains($por['BILL BORRACHA']['observacoes'], 'Orçamento: 3000'), 'campos ficam em Dados do ClickUp, nao nas observacoes');
    eq('Em onboarding', $por['CLIENTE NOVO']['observacoes'], 'sem markdown, usa a descricao simples');
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

/** ClickUp de mentira para comentarios: guarda o que foi publicado e devolve os comentarios da tarefa. */
function clickup_comentarios(ArrayObject $log, array $comentarios, int $statusPost = 200): callable
{
    return function (string $url, string $token, string $metodo = 'GET', ?array $corpo = null) use ($log, $comentarios, $statusPost): array {
        $log[] = [$metodo, (string) parse_url($url, PHP_URL_PATH), $corpo];

        if ($metodo === 'POST') {
            return $statusPost === 200 ? ['status' => 200, 'json' => ['id' => '9001', 'date' => '1']] : ['status' => $statusPost, 'json' => ['err' => 'x']];
        }

        return ['status' => 200, 'json' => ['comments' => $comentarios]];
    };
}

function cenario_comentarios(array $comentarios = [], int $statusPost = 200): array
{
    [$n, $admin, $ana] = nucleo_teste();
    $log = new ArrayObject();
    $imp = new Importacao($n->pdo, $n->clientes, clickup_comentarios($log, $comentarios, $statusPost), 'chave-de-teste-123');
    $imp->salvarToken('pk_x');
    $ligado = $n->clientes->criar($ana, ['nome' => 'GABRIEL VISAGISTA']);
    $n->pdo->prepare("UPDATE clientes SET clickup_id = 'tk1' WHERE id = ?")->execute([$ligado]);
    $solto = $n->clientes->criar($ana, ['nome' => 'SEM CLICKUP']);

    return [$imp, $n, $ana, $ligado, $solto, $log];
}

t('nota de cliente ligado ao ClickUp vira comentario la e nao duplica ao puxar', function (): void {
    [$imp, $n, $ana, $ligado, , $log] = cenario_comentarios();
    eq(['clickup' => true, 'erro' => null], $imp->enviarNota($ana, $ligado, 'Subi 3 criativos novos'));
    eq(['POST', '/api/v2/task/tk1/comment', ['comment_text' => 'Subi 3 criativos novos', 'notify_all' => false]], $log[0]);
    $notas = $n->clientes->notas($ana, $ligado);
    eq([['Ana', 'Subi 3 criativos novos', 'clickup']], array_map(fn ($x) => [$x['autor'], $x['texto'], $x['origem']], $notas));

    // ao puxar, o mesmo comentario (id 9001) volta do ClickUp e nao vira uma segunda nota
    $imp2 = new Importacao($n->pdo, $n->clientes, clickup_comentarios(new ArrayObject(), [['id' => '9001', 'comment_text' => 'Subi 3 criativos novos', 'user' => ['username' => 'mateus'], 'date' => ms('2026-11-16')]]), 'chave-de-teste-123');
    eq(0, $imp2->sincronizarComentarios($ligado, 'tk1'));
    eq(1, count($n->clientes->notas($ana, $ligado)));
});

t('nota de cliente sem ClickUp fica so aqui; se o ClickUp falhar, a nota nao se perde', function (): void {
    [$imp, $n, $ana, $ligado, $solto, $log] = cenario_comentarios([], 500);
    eq(['clickup' => false, 'erro' => null], $imp->enviarNota($ana, $solto, 'Só aqui'));
    eq(0, count($log), 'cliente sem ClickUp nao chama o ClickUp');
    $r = $imp->enviarNota($ana, $ligado, 'Tentei enviar');
    eq(false, $r['clickup']);
    ok(str_contains((string) $r['erro'], '500'), 'motivo do erro volta');
    eq(['Tentei enviar'], array_column($n->clientes->notas($ana, $ligado), 'texto'), 'salva aqui mesmo assim');
    lanca(fn () => $imp->enviarNota($ana, $ligado, '   '), 'Escreva a nota');
});

t('comentarios do ClickUp chegam com a formatacao (titulo, negrito, lista) e no maximo 1 vez por minuto', function (): void {
    $rico = ['id' => 'c7', 'user' => ['username' => 'Vitória'], 'date' => ms('2026-11-13'), 'comment_text' => 'PLANO DE AÇÃO INICIAL ...', 'comment' => [
        ['text' => 'PLANO DE AÇÃO INICIAL'], ['text' => "\n", 'attributes' => ['header' => 2]],
        ['text' => 'Posicionar '], ['text' => 'Gabriel Visagista', 'attributes' => ['bold' => true]], ['text' => ' como marca.'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet']]],
        ['text' => 'Priorizar '], ['text' => 'consultorias ', 'attributes' => ['bold' => true]], ['text' => 'e '], ['text' => 'site', 'attributes' => ['link' => 'https://exemplo.com']], ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet']]],
        ['text' => "Validar orçamento semanal.\n"],
    ]];
    [$imp, $n, $ana, $ligado, , $log] = cenario_comentarios([$rico]);
    $imp->sincronizarSeNecessario($n->clientes->buscar($ana, $ligado));
    $nota = $n->clientes->notas($ana, $ligado)[0];
    eq('Vitória', $nota['autor']);
    eq("## PLANO DE AÇÃO INICIAL\n- Posicionar **Gabriel Visagista** como marca.\n- Priorizar **consultorias** e [site](https://exemplo.com)\nValidar orçamento semanal.", $nota['texto']);
    ok(str_contains(texto_rico($nota['texto']), '<li>Posicionar <strong>Gabriel Visagista</strong> como marca.</li>'));
    $imp->sincronizarSeNecessario($n->clientes->buscar($ana, $ligado));
    eq(1, count($log), 'segunda abertura no mesmo minuto nao chama o ClickUp de novo');
    $imp->sincronizarSeNecessario($n->clientes->buscar($ana, $n->clientes->criar($ana, ['nome' => 'Solto'])));
    eq(1, count($log), 'cliente sem ClickUp nao sincroniza');
});
