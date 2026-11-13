<?php

declare(strict_types=1);

// Banco (migracao) e autenticacao: cadastro de usuarios, login, bloqueio por tentativas, papeis.

t('migracao cria as tabelas e e repetivel', function (): void {
    $pdo = banco_teste();
    Db::migrate($pdo);
    $tabelas = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

    foreach (['usuarios', 'clientes', 'cliente_notas', 'listas', 'status', 'tarefas', 'etiquetas', 'tarefa_etiquetas', 'tarefa_itens', 'tarefa_atividades', 'automacoes', 'automacao_execucoes', 'configuracoes', 'tentativas_login'] as $t) {
        ok(in_array($t, $tabelas, true), "falta a tabela {$t}");
    }

    foreach (['andamento', 'checklist_modelo', 'checklist_marcas', 'registros'] as $antiga) {
        ok(!in_array($antiga, $tabelas, true), "a tabela antiga {$antiga} foi removida");
    }

    eq(2, (int) $pdo->query('SELECT COUNT(*) FROM listas')->fetchColumn(), 'listas padrao semeadas uma unica vez');
});

t('cria usuario com senha em hash e valida os campos', function (): void {
    $auth = new Auth(banco_teste());
    $id = $auth->criar('Maria Souza', 'Maria@Exemplo.com', 'senhaforte1', 'gestor');
    $u = $auth->buscar($id);
    eq('maria@exemplo.com', $u['email'], 'e-mail em minusculas');
    eq('gestor', $u['papel']);
    ok(!isset($u['senha_hash']), 'o hash nunca sai de buscar()');
    lanca(fn () => $auth->criar('X', 'maria@exemplo.com', 'senhaforte1', 'gestor'), 'já existe');
    lanca(fn () => $auth->criar('X', 'in valido', 'senhaforte1', 'gestor'), 'e-mail');
    lanca(fn () => $auth->criar('X', 'ab', 'senhaforte1', 'gestor'), 'login');
    lanca(fn () => $auth->criar('X', 'x@exemplo.com', 'curta', 'gestor'), 'senha');
    lanca(fn () => $auth->criar('', 'y@exemplo.com', 'senhaforte1', 'gestor'), 'nome');
    lanca(fn () => $auth->criar('X', 'z@exemplo.com', 'senhaforte1', 'rei'), 'papel');
});

t('login simples (sem e-mail) tambem vale: cria, entra e nao duplica', function (): void {
    $auth = new Auth(banco_teste());
    $id = $auth->criar('Administrador', 'Admin', 'senhaforte1', 'admin');
    eq('admin', $auth->buscar($id)['email'], 'login guardado em minusculas');
    ok($auth->autenticar('ADMIN', 'senhaforte1', '1.1.1.1') !== null, 'entra com o login');
    eq(null, $auth->autenticar('admin', 'errada123', '1.1.1.1'), 'senha errada');
    lanca(fn () => $auth->criar('Outro', 'admin', 'senhaforte1', 'gestor'), 'já existe');
});

t('login certo devolve o usuario e errado devolve nulo', function (): void {
    $auth = new Auth(banco_teste());
    $auth->criar('Ana', 'ana@exemplo.com', 'senhaforte1', 'admin');
    $u = $auth->autenticar('ANA@exemplo.com', 'senhaforte1', '1.1.1.1');
    ok($u !== null && $u['nome'] === 'Ana', 'login certo');
    eq(null, $auth->autenticar('ana@exemplo.com', 'errada123', '1.1.1.1'), 'senha errada');
    eq(null, $auth->autenticar('naoexiste@exemplo.com', 'senhaforte1', '1.1.1.1'), 'e-mail inexistente');
});

t('usuario inativo nao entra', function (): void {
    $auth = new Auth(banco_teste());
    $id = $auth->criar('Bia', 'bia@exemplo.com', 'senhaforte1', 'gestor');
    $auth->definirAtivo($id, false);
    eq(null, $auth->autenticar('bia@exemplo.com', 'senhaforte1', '1.1.1.1'));
    $auth->definirAtivo($id, true);
    ok($auth->autenticar('bia@exemplo.com', 'senhaforte1', '1.1.1.1') !== null);
});

t('5 erros seguidos bloqueiam o login ate mesmo com a senha certa', function (): void {
    $auth = new Auth(banco_teste());
    $auth->criar('Caio', 'caio@exemplo.com', 'senhaforte1', 'gestor');

    for ($i = 0; $i < 5; $i++) {
        $auth->autenticar('caio@exemplo.com', 'errada' . $i . 'xx', '2.2.2.2');
    }

    ok($auth->bloqueado('caio@exemplo.com', '2.2.2.2'), 'ficou bloqueado');
    eq(null, $auth->autenticar('caio@exemplo.com', 'senhaforte1', '2.2.2.2'), 'bloqueado mesmo com a senha certa');
    ok(!$auth->bloqueado('caio@exemplo.com', '9.9.9.9'), 'o bloqueio vale para aquele e-mail + IP, nao para outro IP');
    ok($auth->autenticar('caio@exemplo.com', 'senhaforte1', '9.9.9.9') !== null, 'de outro IP o dono ainda entra');
});

t('login certo zera as tentativas e troca de senha funciona', function (): void {
    $auth = new Auth(banco_teste());
    $id = $auth->criar('Dani', 'dani@exemplo.com', 'senhaforte1', 'gestor');
    $auth->autenticar('dani@exemplo.com', 'errada111', '3.3.3.3');
    ok($auth->autenticar('dani@exemplo.com', 'senhaforte1', '3.3.3.3') !== null);
    ok(!$auth->bloqueado('dani@exemplo.com', '3.3.3.3'));
    $auth->trocarSenha($id, 'outrasenha22');
    eq(null, $auth->autenticar('dani@exemplo.com', 'senhaforte1', '3.3.3.3'), 'senha antiga nao vale mais');
    ok($auth->autenticar('dani@exemplo.com', 'outrasenha22', '3.3.3.3') !== null);
    lanca(fn () => $auth->trocarSenha($id, 'curta'), 'senha');
});

t('listar traz todos sem o hash e conta usuarios', function (): void {
    $auth = new Auth(banco_teste());
    eq(0, $auth->total());
    $auth->criar('A', 'a@exemplo.com', 'senhaforte1', 'admin');
    $auth->criar('B', 'b@exemplo.com', 'senhaforte1', 'gestor');
    eq(2, $auth->total());
    $lista = $auth->listar();
    eq(2, count($lista));
    ok(!isset($lista[0]['senha_hash']));
});
