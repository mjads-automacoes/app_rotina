<?php

declare(strict_types=1);

// Camada web, sem servidor: App::handle(Request) -> Response. Login, CSRF, permissoes, API (tarefas, rotina, automacoes) e escape de HTML.

/** Relogio do app nos testes: 2026-11-09 (segunda) 06:30. */
function novo_app(): array
{
    $pdo = banco_teste();

    return [new App($pdo, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-09 06:30:00')), $pdo, new Sessao()];
}

function req(App $app, Sessao $s, string $metodo, string $caminho, array $post = [], array $query = [], array $headers = [], string $ip = '10.0.0.1'): Response
{
    return $app->handle(new Request($metodo, $caminho, $query, $post, $headers, $ip, $s));
}

/** GET numa pagina so para a sessao ganhar o token CSRF; devolve o token. */
function token(App $app, Sessao $s): string
{
    req($app, $s, 'GET', '/login');

    return (string) $s->d['csrf'];
}

function configurar_admin(App $app, Sessao $s, string $email = 'admin@exemplo.com'): void
{
    $t = token($app, $s);
    $r = req($app, $s, 'POST', '/setup', ['csrf' => $t, 'nome' => 'Admin', 'email' => $email, 'senha' => 'senhaforte1']);
    eq(302, $r->status, 'setup redireciona');
}

function logar(App $app, Sessao $s, string $email, string $senha = 'senhaforte1'): Response
{
    return req($app, $s, 'POST', '/login', ['csrf' => token($app, $s), 'email' => $email, 'senha' => $senha]);
}

/** POST da API (JSON) ja com o cabecalho CSRF da sessao. */
function api(App $app, Sessao $s, string $caminho, array $dados = []): array
{
    $r = req($app, $s, 'POST', $caminho, $dados, [], ['X-CSRF-Token' => (string) $s->d['csrf']]);

    return ['status' => $r->status] + (json_decode($r->body, true) ?: []);
}

function apiget(App $app, Sessao $s, string $caminho, array $query = []): array
{
    $r = req($app, $s, 'GET', $caminho, [], $query);

    return ['status' => $r->status] + (json_decode($r->body, true) ?: []);
}

t('sem usuarios, qualquer pagina manda para o setup; depois do setup ele fecha', function (): void {
    [$app, , $s] = novo_app();
    $r = req($app, $s, 'GET', '/');
    eq(302, $r->status);
    eq('/setup', $r->headers['Location']);
    eq(200, req($app, $s, 'GET', '/setup')->status);
    configurar_admin($app, $s);
    $outra = new Sessao();
    eq('/login', req($app, $outra, 'GET', '/setup')->headers['Location'], 'setup fecha depois do primeiro usuario');
    eq(403, req($app, $outra, 'POST', '/setup', ['csrf' => token($app, $outra), 'nome' => 'Hacker', 'email' => 'h@exemplo.com', 'senha' => 'senhaforte1'])->status, 'POST no setup fechado e recusado');
});

t('o setup cria o admin ja logado e renova o id da sessao', function (): void {
    [$app, , $s] = novo_app();
    configurar_admin($app, $s);
    eq(200, req($app, $s, 'GET', '/')->status, 'entra direto na rotina');
    ok($s->renovacoes >= 1, 'o id da sessao foi renovado ao logar');
});

t('login: errado volta com erro, certo entra e sair encerra', function (): void {
    [$app, , $adm] = novo_app();
    configurar_admin($app, $adm);
    $s = new Sessao();
    $r = logar($app, $s, 'admin@exemplo.com', 'errada1234');
    eq(200, $r->status);
    ok(str_contains($r->body, 'Login ou senha incorretos'), 'mensagem generica');
    eq(302, req($app, $s, 'GET', '/')->status, 'continua fora');
    eq('/', logar($app, $s, 'admin@exemplo.com')->headers['Location']);
    eq(200, req($app, $s, 'GET', '/')->status);
    eq('/login', req($app, $s, 'POST', '/logout', ['csrf' => (string) $s->d['csrf']])->headers['Location']);
    eq(302, req($app, $s, 'GET', '/')->status, 'saiu');
});

t('POST sem token CSRF e recusado em formularios e na API', function (): void {
    [$app, , $s] = novo_app();
    configurar_admin($app, $s);
    eq(403, req($app, $s, 'POST', '/clientes', ['nome' => 'X'])->status);
    eq(403, req($app, $s, 'POST', '/api/tarefas', ['titulo' => 'X'])->status);
    eq(403, req($app, $s, 'POST', '/logout')->status);
    $ruim = new Sessao();
    token($app, $ruim);
    eq(403, req($app, $ruim, 'POST', '/login', ['csrf' => 'adivinhado', 'email' => 'a@b.co', 'senha' => 'x'])->status);
});

t('a API exige login e responde JSON', function (): void {
    [$app, , $s] = novo_app();
    configurar_admin($app, new Sessao());
    $r = req($app, $s, 'GET', '/api/tarefas');
    eq(401, $r->status);
    eq('application/json; charset=utf-8', $r->headers['Content-Type']);
});

function cenario_http(): array
{
    [$app, $pdo, $admin] = novo_app();
    configurar_admin($app, $admin);
    $auth = new Auth($pdo);
    $auth->criar('Ana', 'ana@exemplo.com', 'senhaforte1', 'gestor');
    $auth->criar('Beto', 'beto@exemplo.com', 'senhaforte1', 'gestor');
    $ana = new Sessao();
    $beto = new Sessao();
    logar($app, $ana, 'ana@exemplo.com');
    logar($app, $beto, 'beto@exemplo.com');

    return [$app, $pdo, $admin, $ana, $beto];
}

t('todas as paginas abrem para o admin logado', function (): void {
    [$app, , $admin] = cenario_http();

    foreach (['/', '/clientes', '/automacoes', '/historico', '/importar', '/equipe', '/conta'] as $p) {
        $r = req($app, $admin, 'GET', $p);
        eq(200, $r->status, $p . ' => ' . $r->status);
        ok(str_contains($r->body, 'data-theme-toggle'), $p . ' tem o botao de tema');
    }
});

t('clientes: cria pela tela com a ficha completa, lista so os meus e escapa HTML', function (): void {
    [$app, $pdo, , $ana, $beto] = cenario_http();
    $csrf = (string) $ana->d['csrf'];
    $r = req($app, $ana, 'POST', '/clientes', ['csrf' => $csrf, 'nome' => 'Loja <script>alert(1)</script>', 'contato_nome' => 'Loja LTDA', 'dias_enviado' => '1', 'dias' => ['segunda'], 'plataformas_enviado' => '1', 'plataformas' => ['google'], 'turno' => 'manha']);
    eq(302, $r->status, $r->body);
    req($app, $beto, 'POST', '/clientes', ['csrf' => (string) $beto->d['csrf'], 'nome' => 'Cliente do Beto']);
    $pag = req($app, $ana, 'GET', '/clientes')->body;
    ok(str_contains($pag, 'Loja &lt;script&gt;alert(1)&lt;/script&gt;'), 'nome escapado');
    ok(!str_contains($pag, '<script>alert(1)'), 'sem script injetado');
    ok(!str_contains($pag, 'Cliente do Beto'), 'nao ve cliente de outro gestor');
    $id = (int) $pdo->query("SELECT id FROM clientes WHERE nome LIKE 'Loja%'")->fetchColumn();
    $ficha = req($app, $ana, 'GET', "/clientes/{$id}");
    eq(200, $ficha->status);
    ok(str_contains($ficha->body, 'Loja LTDA'));
    eq(404, req($app, $beto, 'GET', "/clientes/{$id}")->status, 'ficha de outro gestor');
});

t('clientes: erro de validacao volta com a mensagem uma unica vez e nao cria', function (): void {
    [$app, $pdo, , $ana] = cenario_http();
    $r = req($app, $ana, 'POST', '/clientes', ['csrf' => (string) $ana->d['csrf'], 'nome' => '']);
    eq(302, $r->status);
    ok(str_contains(req($app, $ana, 'GET', '/clientes')->body, 'Informe o nome do cliente'));
    ok(!str_contains(req($app, $ana, 'GET', '/clientes')->body, 'Informe o nome do cliente'), 'some no proximo carregamento');
    eq(0, (int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn());
});

t('clientes: editar, anotar e excluir so os proprios', function (): void {
    [$app, $pdo, , $ana, $beto] = cenario_http();
    req($app, $beto, 'POST', '/clientes', ['csrf' => (string) $beto->d['csrf'], 'nome' => 'Do Beto']);
    $id = (int) $pdo->query('SELECT id FROM clientes')->fetchColumn();
    eq(404, req($app, $ana, 'POST', "/clientes/{$id}", ['csrf' => (string) $ana->d['csrf'], 'nome' => 'Invadido'])->status);
    eq(404, req($app, $ana, 'POST', "/clientes/{$id}/notas", ['csrf' => (string) $ana->d['csrf'], 'texto' => 'x'])->status);
    eq(404, req($app, $ana, 'POST', "/clientes/{$id}/excluir", ['csrf' => (string) $ana->d['csrf']])->status);
    eq('Do Beto', $pdo->query('SELECT nome FROM clientes')->fetchColumn(), 'nada mudou');
    eq(302, req($app, $beto, 'POST', "/clientes/{$id}", ['csrf' => (string) $beto->d['csrf'], 'nome' => 'Renomeado', 'turno' => 'tarde'])->status);
    eq('Renomeado', $pdo->query('SELECT nome FROM clientes')->fetchColumn());
    req($app, $beto, 'POST', "/clientes/{$id}/notas", ['csrf' => (string) $beto->d['csrf'], 'texto' => 'Reunião na sexta']);
    ok(str_contains(req($app, $beto, 'GET', "/clientes/{$id}")->body, 'Reunião na sexta'));
    eq(302, req($app, $beto, 'POST', "/clientes/{$id}/excluir", ['csrf' => (string) $beto->d['csrf']])->status);
    eq(0, (int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn());
});

t('API de tarefas: meta, criar, listar, abrir, editar, mover, checklist, comentar e excluir', function (): void {
    [$app, , , $ana] = cenario_http();
    $meta = apiget($app, $ana, '/api/meta');
    eq(200, $meta['status']);
    eq(['Rotina diária', 'Geral'], array_column($meta['listas'], 'nome'));
    $c = api($app, $ana, '/api/tarefas', ['titulo' => 'Revisar campanha', 'lista_id' => 2, 'prioridade' => 2, 'prazo' => '2026-11-12', 'etiquetas' => ['meta ads']]);
    eq(200, $c['status'], json_encode($c));
    $id = $c['id'];
    $lista = apiget($app, $ana, '/api/tarefas', ['abertas' => '1', 'lista_id' => '2']);
    eq(['Revisar campanha'], array_column($lista['tarefas'], 'titulo'));
    $t = apiget($app, $ana, "/api/tarefas/{$id}");
    eq('A fazer', $t['tarefa']['status_nome']);
    eq(200, api($app, $ana, "/api/tarefas/{$id}", ['titulo' => 'Revisar campanha Meta', 'prioridade' => 1, 'etiquetas' => 'meta ads, urgente'])['status']);
    $t = apiget($app, $ana, "/api/tarefas/{$id}")['tarefa'];
    eq('Revisar campanha Meta', $t['titulo']);
    eq(['meta ads', 'urgente'], array_column($t['etiquetas'], 'nome'));
    $emAndamento = $meta['listas'][1]['status'][1]['id'];
    eq(200, api($app, $ana, "/api/tarefas/{$id}/status", ['status_id' => $emAndamento])['status']);
    $item = api($app, $ana, "/api/tarefas/{$id}/itens", ['texto' => 'Conferir orçamento']);
    eq(200, api($app, $ana, '/api/itens/' . $item['id'], ['feito' => '1'])['status']);
    eq(200, api($app, $ana, "/api/tarefas/{$id}/comentarios", ['texto' => 'Aprovado pelo cliente'])['status']);
    $t = apiget($app, $ana, "/api/tarefas/{$id}")['tarefa'];
    eq('Em andamento', $t['status_nome']);
    eq([true], array_column($t['itens'], 'feito'));
    eq('Aprovado pelo cliente', $t['atividades'][array_key_last($t['atividades'])]['texto']);
    eq(422, api($app, $ana, '/api/tarefas', ['titulo' => '', 'lista_id' => 2])['status'], 'validacao = 422');
    eq(200, api($app, $ana, "/api/tarefas/{$id}/excluir")['status']);
    eq(404, apiget($app, $ana, "/api/tarefas/{$id}")['status']);
});

t('API de tarefas: outro gestor nao ve nem mexe', function (): void {
    [$app, , , $ana, $beto] = cenario_http();
    $id = api($app, $ana, '/api/tarefas', ['titulo' => 'Da Ana', 'lista_id' => 2])['id'];
    eq(404, apiget($app, $beto, "/api/tarefas/{$id}")['status']);
    eq(404, api($app, $beto, "/api/tarefas/{$id}", ['titulo' => 'Invadida'])['status']);
    eq(404, api($app, $beto, "/api/tarefas/{$id}/excluir")['status']);
    eq([], apiget($app, $beto, '/api/tarefas', ['lista_id' => '2'])['tarefas']);
});

t('API da rotina: gera o dia, mostra, inicia, conclui com nota e reabre', function (): void {
    [$app, $pdo, , $ana] = cenario_http();
    (new Clientes($pdo))->criar((new Auth($pdo))->buscar(2), ['nome' => 'BILL BORRACHA', 'dias' => ['segunda'], 'turno' => 'manha']);
    $r = apiget($app, $ana, '/api/rotina', ['data' => '2026-11-09']);
    eq(200, $r['status']);
    $dia = $r['semana']['2026-11-09'];
    $cliente = $dia['turnos']['manha']['clientes'][0];
    eq('Analisar BILL BORRACHA', $cliente['titulo'], 'a agenda (tick preguicoso) ja gerou a rotina de hoje');
    eq(4, count($dia['turnos']['manha']['checklist']));
    eq(200, api($app, $ana, '/api/rotina/iniciar', ['tarefa_id' => $cliente['id']])['status']);
    eq(200, api($app, $ana, '/api/rotina/concluir', ['tarefa_id' => $cliente['id'], 'nota' => 'Ajustei o orçamento'])['status']);
    $dia = apiget($app, $ana, '/api/rotina', ['data' => '2026-11-09'])['semana']['2026-11-09'];
    eq('fechado', $dia['turnos']['manha']['clientes'][0]['status_tipo']);
    eq(1, $dia['feitas']);
    eq(200, api($app, $ana, '/api/rotina/reabrir', ['tarefa_id' => $cliente['id']])['status']);
    eq(0, apiget($app, $ana, '/api/rotina', ['data' => '2026-11-09'])['semana']['2026-11-09']['feitas']);
    eq(422, apiget($app, $ana, '/api/rotina', ['data' => 'ontem'])['status']);
});

t('API de automacoes: todos leem; so o admin cria, edita, liga, executa e exclui', function (): void {
    [$app, , $admin, $ana] = cenario_http();
    $lista = apiget($app, $ana, '/api/automacoes');
    eq(200, $lista['status']);
    ok(count($lista['automacoes']) >= 4, 'padroes visiveis para o gestor');
    $nova = ['nome' => 'Comentar ao criar', 'gatilho' => 'tarefa_criada', 'condicoes' => ['lista_id' => 2], 'acoes' => [['tipo' => 'comentar', 'texto' => 'Bem-vinda']]];
    eq(403, api($app, $ana, '/api/automacoes', $nova)['status'], 'gestor nao cria');
    $c = api($app, $admin, '/api/automacoes', $nova);
    eq(200, $c['status'], json_encode($c));
    $id = $c['id'];
    eq(422, api($app, $admin, '/api/automacoes', ['nome' => 'x', 'gatilho' => 'agenda', 'acoes' => $nova['acoes']])['status'], 'agenda sem dias e recusada');
    eq(200, api($app, $admin, "/api/automacoes/{$id}/alternar", ['ativa' => '0'])['status']);
    eq(403, api($app, $ana, "/api/automacoes/{$id}/excluir")['status']);
    eq(200, api($app, $admin, "/api/automacoes/{$id}", ['nome' => 'Renomeada'] + $nova)['status']);
    $padrao = array_values(array_filter($lista['automacoes'], fn ($a) => $a['nome'] === 'Rotina diária dos clientes'))[0]['id'];
    eq(200, api($app, $admin, "/api/automacoes/{$padrao}/executar")['status']);
    ok(isset(apiget($app, $admin, "/api/automacoes/{$padrao}/execucoes")['execucoes']));
    eq(200, api($app, $admin, "/api/automacoes/{$id}/excluir")['status']);
});

t('equipe: so admin ve e cria gestores; troca de senha e ativar/desativar; admin nao se desativa', function (): void {
    [$app, $pdo, $admin, $ana] = cenario_http();
    eq(403, req($app, $ana, 'GET', '/equipe')->status, 'gestor nao acessa');
    eq(403, req($app, $ana, 'POST', '/equipe', ['csrf' => (string) $ana->d['csrf'], 'nome' => 'X', 'email' => 'x@exemplo.com', 'senha' => 'senhaforte1'])->status);
    eq(302, req($app, $admin, 'POST', '/equipe', ['csrf' => (string) $admin->d['csrf'], 'nome' => 'Carla', 'email' => 'carla@exemplo.com', 'senha' => 'senhaforte1'])->status);
    $c = (int) $pdo->query("SELECT id FROM usuarios WHERE email='carla@exemplo.com'")->fetchColumn();
    eq('gestor', $pdo->query("SELECT papel FROM usuarios WHERE id={$c}")->fetchColumn());
    req($app, $admin, 'POST', "/equipe/{$c}/ativo", ['csrf' => (string) $admin->d['csrf'], 'ativo' => '0']);
    eq(200, logar($app, new Sessao(), 'carla@exemplo.com')->status, 'desativada nao entra');
    eq(302, req($app, $admin, 'POST', "/equipe/{$c}/senha", ['csrf' => (string) $admin->d['csrf'], 'senha' => 'novasenha99'])->status);
    $idAdmin = (int) $pdo->query("SELECT id FROM usuarios WHERE papel='admin'")->fetchColumn();
    req($app, $admin, 'POST', "/equipe/{$idAdmin}/ativo", ['csrf' => (string) $admin->d['csrf'], 'ativo' => '0']);
    eq(1, (int) $pdo->query("SELECT ativo FROM usuarios WHERE id={$idAdmin}")->fetchColumn());
});

t('conta: trocar a propria senha exige a senha atual', function (): void {
    [$app, , , $ana] = cenario_http();
    eq(302, req($app, $ana, 'POST', '/conta/senha', ['csrf' => (string) $ana->d['csrf'], 'atual' => 'errada123', 'nova' => 'outrasenha22'])->status);
    ok(logar($app, new Sessao(), 'ana@exemplo.com', 'outrasenha22')->status === 200, 'senha nao mudou com a atual errada');
    req($app, $ana, 'POST', '/conta/senha', ['csrf' => (string) $ana->d['csrf'], 'atual' => 'senhaforte1', 'nova' => 'outrasenha22']);
    eq(302, logar($app, new Sessao(), 'ana@exemplo.com', 'outrasenha22')->status, 'senha mudou');
});

t('historico mostra as conclusoes do gestor com a nota', function (): void {
    [$app, $pdo, , $ana, $beto] = cenario_http();
    (new Clientes($pdo))->criar((new Auth($pdo))->buscar(2), ['nome' => 'BILL BORRACHA', 'dias' => ['segunda'], 'turno' => 'manha']);
    $cli = apiget($app, $ana, '/api/rotina', ['data' => '2026-11-09'])['semana']['2026-11-09']['turnos']['manha']['clientes'][0];
    api($app, $ana, '/api/rotina/concluir', ['tarefa_id' => $cli['id'], 'nota' => 'Ajustei o orçamento']);
    $pag = req($app, $ana, 'GET', '/historico')->body;
    ok(str_contains($pag, 'BILL BORRACHA') && str_contains($pag, 'Ajustei o orçamento'));
    ok(!str_contains(req($app, $beto, 'GET', '/historico')->body, 'BILL BORRACHA'), 'outro gestor nao ve');
});

t('importar: so o admin guarda o token; a tela nunca mostra o token', function (): void {
    [$app, , $admin, $ana] = cenario_http();
    eq(403, req($app, $ana, 'POST', '/importar/token', ['csrf' => (string) $ana->d['csrf'], 'token' => 'pk_x'])->status);
    eq(302, req($app, $admin, 'POST', '/importar/token', ['csrf' => (string) $admin->d['csrf'], 'token' => 'pk_segredo_xyz'])->status);
    $pag = req($app, $admin, 'GET', '/importar')->body;
    ok(!str_contains($pag, 'pk_segredo_xyz'), 'token nao aparece');
    ok(str_contains($pag, 'Conectado'));
});

t('cabecalhos de seguranca, tema claro/escuro e nome do produto vem da configuracao', function (): void {
    [$app, , $s] = novo_app();
    $r = req($app, $s, 'GET', '/setup');
    eq('nosniff', $r->headers['X-Content-Type-Options']);
    eq('SAMEORIGIN', $r->headers['X-Frame-Options']);
    ok(str_contains($r->headers['Content-Security-Policy'], "default-src 'self'"));
    ok(str_contains($r->body, e((string) rotina_config('app_name'))), 'o nome vem de rotina_config("app_name")');
    ok(str_contains($r->body, '/assets/theme.js'), 'tema aplicado antes de pintar (sem piscar)');
    configurar_admin($app, $s);
    eq(404, req($app, $s, 'GET', '/nao-existe')->status, 'rota inexistente (logado)');
});
