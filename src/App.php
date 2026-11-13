<?php

declare(strict_types=1);

require_once __DIR__ . '/Http.php';

final class App
{
    private Nucleo $n;
    /** @var callable(): DateTimeImmutable */
    private $relogio;

    public function __construct(PDO $pdo, ?callable $relogio = null)
    {
        $this->n = Nucleo::montar($pdo);
        $this->relogio = $relogio ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function handle(Request $r): Response
    {
        $r->sessao->d['csrf'] ??= bin2hex(random_bytes(32));

        try {
            $resp = $this->rotear($r);
        } catch (Throwable $e) {
            error_log('[rotina] ' . $e::class . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
            $resp = str_starts_with($r->caminho, '/api/')
                ? Response::json(['ok' => false, 'message' => 'Erro inesperado. Tente de novo.'], 500)
                : $this->tela($r, null, 'erro', ['titulo' => 'Algo deu errado', 'msg' => 'Erro inesperado. Tente de novo.'], 500);
        }

        $resp->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'self'; base-uri 'self'",
        ];

        return $resp;
    }

    private function agora(): DateTimeImmutable
    {
        return ($this->relogio)();
    }

    private function rotear(Request $r): Response
    {
        $caminho = $r->caminho === '/' ? '/' : rtrim($r->caminho, '/');
        $api = str_starts_with($caminho, '/api/');

        if ($r->metodo === 'POST' && !$this->csrfOk($r)) {
            return $api ? Response::json(['ok' => false, 'message' => 'Sessão expirada. Recarregue a página.'], 403)
                : $this->erro($r, 403, 'Sessão expirada', 'Recarregue a página e tente de novo.');
        }

        if ($caminho === '/setup') {
            return $this->setup($r);
        }

        if ($this->n->auth->total() === 0) {
            return Response::redirect('/setup');
        }

        if ($caminho === '/login') {
            return $this->login($r);
        }

        $u = $this->usuarioLogado($r);

        if ($u === null) {
            return $api ? Response::json(['ok' => false, 'message' => 'Entre para continuar.'], 401) : Response::redirect('/login');
        }

        if ($caminho === '/logout' && $r->metodo === 'POST') {
            unset($r->sessao->d['uid']);
            $r->sessao->renovar();

            return Response::redirect('/login');
        }

        // As automacoes rodam aqui dentro: a cada requisicao (no maximo 1 vez por minuto) o sistema ve o que esta na hora.
        try {
            $this->n->automacoes->tickSeNecessario($this->agora());
        } catch (Throwable $e) {
            error_log('[rotina] tick falhou: ' . $e->getMessage());
        }

        try {
            return $api ? $this->api($r, $caminho, $u) : $this->paginas($r, $caminho, $u);
        } catch (InvalidArgumentException $e) {
            if ($api) {
                return Response::json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            $this->flash($r, 'erro', $e->getMessage());

            return Response::redirect($this->voltarPara($caminho));
        } catch (AcessoNegado $e) {
            return $api ? Response::json(['ok' => false, 'message' => $e->getMessage()], 403) : $this->erro($r, 403, 'Acesso restrito', $e->getMessage(), $u);
        } catch (RuntimeException $e) {
            return $api ? Response::json(['ok' => false, 'message' => $e->getMessage()], 404) : $this->erro($r, 404, 'Não encontrado', $e->getMessage(), $u);
        }
    }

    private function voltarPara(string $caminho): string
    {
        if (preg_match('#^/(clientes/\d+|clientes|equipe|conta|importar)#', $caminho, $m)) {
            return '/' . $m[1];
        }

        return '/';
    }

    // ---------- autenticacao ----------

    private function setup(Request $r): Response
    {
        if ($this->n->auth->total() > 0) {
            return $r->metodo === 'POST' ? $this->erro($r, 403, 'Cadastro inicial encerrado', 'Já existe um administrador. Entre com a sua conta.') : Response::redirect('/login');
        }

        if ($r->metodo === 'POST') {
            try {
                $id = $this->n->auth->criar((string) ($r->post['nome'] ?? ''), (string) ($r->post['email'] ?? ''), (string) ($r->post['senha'] ?? ''), 'admin');
                $r->sessao->d['uid'] = $id;
                $r->sessao->renovar();

                return Response::redirect('/');
            } catch (InvalidArgumentException $e) {
                return $this->tela($r, null, 'setup', ['erro' => $e->getMessage(), 'valores' => $r->post], 422);
            }
        }

        return $this->tela($r, null, 'setup', ['erro' => '', 'valores' => []]);
    }

    private function login(Request $r): Response
    {
        if ($this->usuarioLogado($r) !== null) {
            return Response::redirect('/');
        }

        if ($r->metodo === 'POST') {
            $email = (string) ($r->post['email'] ?? '');

            if ($this->n->auth->bloqueado($email, $r->ip)) {
                return $this->tela($r, null, 'login', ['erro' => 'Muitas tentativas. Aguarde alguns minutos e tente de novo.', 'email' => $email], 429);
            }

            $u = $this->n->auth->autenticar($email, (string) ($r->post['senha'] ?? ''), $r->ip);

            if ($u === null) {
                return $this->tela($r, null, 'login', ['erro' => 'Login ou senha incorretos.', 'email' => $email]);
            }

            $r->sessao->d['uid'] = (int) $u['id'];
            $r->sessao->renovar();

            return Response::redirect('/');
        }

        return $this->tela($r, null, 'login', ['erro' => '', 'email' => '']);
    }

    private function usuarioLogado(Request $r): ?array
    {
        $uid = (int) ($r->sessao->d['uid'] ?? 0);
        $u = $uid > 0 ? $this->n->auth->buscar($uid) : null;

        return $u !== null && (int) $u['ativo'] === 1 ? $u : null;
    }

    private function csrfOk(Request $r): bool
    {
        $enviado = (string) ($r->post['csrf'] ?? $r->header('x-csrf-token'));
        $enviado = $enviado !== '' ? $enviado : $r->header('x-csrf-token');

        return $enviado !== '' && hash_equals((string) $r->sessao->d['csrf'], $enviado);
    }

    // ---------- paginas ----------

    private function paginas(Request $r, string $caminho, array $u): Response
    {
        $admin = $u['papel'] === 'admin';

        if ($caminho === '/' && $r->metodo === 'GET') {
            return $this->tela($r, $u, 'rotina', ['gestores' => $admin ? $this->gestores() : [], 'hoje' => $this->agora()]);
        }

        if ($caminho === '/tarefas' && $r->metodo === 'GET') {
            return Response::redirect('/#tarefas');
        }

        if ($caminho === '/automacoes' && $r->metodo === 'GET') {
            return $this->tela($r, $u, 'automacoes');
        }

        if ($caminho === '/clientes') {
            if ($r->metodo === 'POST') {
                $id = $this->n->clientes->criar($u, $this->dadosCliente($r->post));
                $this->flash($r, 'ok', 'Cliente criado.');

                return Response::redirect('/clientes/' . $id);
            }

            return $this->tela($r, $u, 'clientes', ['lista' => $this->n->clientes->listar($u), 'gestores' => $admin ? $this->gestores() : [], 'nomes' => array_column($this->n->auth->listar(), 'nome', 'id')]);
        }

        if (preg_match('#^/clientes/(\d+)(?:/(excluir|notas))?$#', $caminho, $m)) {
            $id = (int) $m[1];
            $acao = $m[2] ?? '';

            if ($r->metodo === 'POST') {
                if ($acao === 'excluir') {
                    $this->n->clientes->excluir($u, $id);
                    $this->flash($r, 'ok', 'Cliente excluído.');

                    return Response::redirect('/clientes');
                }

                if ($acao === 'notas') {
                    $this->n->clientes->notaAdicionar($u, $id, (string) ($r->post['texto'] ?? ''));
                    $this->flash($r, 'ok', 'Nota adicionada.');
                } else {
                    $this->n->clientes->atualizar($u, $id, $this->dadosCliente($r->post));
                    $this->flash($r, 'ok', 'Cliente atualizado.');
                }

                return Response::redirect('/clientes/' . $id);
            }

            $cliente = $this->n->clientes->buscar($u, $id);

            return $this->tela($r, $u, 'cliente', [
                'cliente' => $cliente,
                'notas' => $this->n->clientes->notas($u, $id),
                'tarefas' => $this->n->tarefas->listar($u, ['cliente_id' => $id], 100),
                'gestor' => $this->n->auth->buscar($cliente['gestor_id'])['nome'] ?? '',
                'gestores' => $this->gestores(),
            ]);
        }

        if ($caminho === '/historico' && $r->metodo === 'GET') {
            $f = array_intersect_key($r->query, array_flip(['de', 'ate', 'cliente_id', 'responsavel_id']));

            return $this->tela($r, $u, 'historico', ['registros' => $this->n->rotina->historico($u, $f), 'clientes' => $this->n->clientes->listar($u), 'filtros' => $f]);
        }

        if ($caminho === '/importar' || str_starts_with($caminho, '/importar/')) {
            return $this->importar($r, $caminho, $u);
        }

        if ($caminho === '/conta') {
            return $this->tela($r, $u, 'conta');
        }

        if ($caminho === '/conta/senha' && $r->metodo === 'POST') {
            $atual = $this->n->pdo->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
            $atual->execute([$u['id']]);

            if (!password_verify((string) ($r->post['atual'] ?? ''), (string) $atual->fetchColumn())) {
                throw new InvalidArgumentException('A senha atual não confere.');
            }

            $this->n->auth->trocarSenha((int) $u['id'], (string) ($r->post['nova'] ?? ''));
            $this->flash($r, 'ok', 'Senha alterada.');

            return Response::redirect('/conta');
        }

        if ($caminho === '/equipe' || str_starts_with($caminho, '/equipe/')) {
            if (!$admin) {
                return $this->erro($r, 403, 'Acesso restrito', 'Só o administrador gerencia a equipe.', $u);
            }

            return $this->equipe($r, $caminho, $u);
        }

        return $this->erro($r, 404, 'Página não encontrada', 'Esse endereço não existe.', $u);
    }

    private function importar(Request $r, string $caminho, array $u): Response
    {
        $imp = $this->n->importacao;

        if ($r->metodo === 'POST') {
            if ($caminho === '/importar/token' || $caminho === '/importar/remover') {
                if ($u['papel'] !== 'admin') {
                    throw new AcessoNegado('Só o administrador conecta o ClickUp.');
                }

                if ($caminho === '/importar/token') {
                    $imp->salvarToken((string) ($r->post['token'] ?? ''));
                    $this->flash($r, 'ok', 'ClickUp conectado.');
                } else {
                    $imp->removerToken();
                    $this->flash($r, 'ok', 'ClickUp desconectado.');
                }

                return Response::redirect('/importar');
            }

            if ($caminho === '/importar/executar') {
                $res = $imp->importar($u, (string) ($r->post['lista_id'] ?? ''), ['gestor_id' => $r->post['gestor_id'] ?? null]);
                $this->flash($r, 'ok', 'Importação concluída: ' . $res['criados'] . ' cliente(s) novo(s), ' . $res['atualizados'] . ' atualizado(s) e ' . $res['notas'] . ' nota(s).');

                return Response::redirect('/importar');
            }

            throw new RuntimeException('Esse endereço não existe.');
        }

        $listas = [];
        $erro = '';

        if ($imp->temToken() && ($r->query['listas'] ?? '') === '1') {
            try {
                $listas = $imp->listas();
            } catch (RuntimeException $e) {
                $erro = $e->getMessage();
            }
        }

        return $this->tela($r, $u, 'importar', ['conectado' => $imp->temToken(), 'listas' => $listas, 'erro' => $erro, 'gestores' => $u['papel'] === 'admin' ? $this->gestores() : []]);
    }

    private function equipe(Request $r, string $caminho, array $u): Response
    {
        if ($caminho === '/equipe' && $r->metodo === 'POST') {
            $this->n->auth->criar((string) ($r->post['nome'] ?? ''), (string) ($r->post['email'] ?? ''), (string) ($r->post['senha'] ?? ''), 'gestor');
            $this->flash($r, 'ok', 'Gestor criado.');

            return Response::redirect('/equipe');
        }

        if (preg_match('#^/equipe/(\d+)/(ativo|senha)$#', $caminho, $m) && $r->metodo === 'POST') {
            $id = (int) $m[1];

            if ($this->n->auth->buscar($id) === null) {
                throw new RuntimeException('Usuário não encontrado.');
            }

            if ($m[2] === 'ativo') {
                if ($id === (int) $u['id']) {
                    throw new InvalidArgumentException('Você não pode desativar a própria conta.');
                }

                $this->n->auth->definirAtivo($id, ($r->post['ativo'] ?? '') === '1');
                $this->flash($r, 'ok', 'Acesso atualizado.');
            } else {
                $this->n->auth->trocarSenha($id, (string) ($r->post['senha'] ?? ''));
                $this->flash($r, 'ok', 'Senha redefinida.');
            }

            return Response::redirect('/equipe');
        }

        if ($caminho === '/equipe' && $r->metodo === 'GET') {
            return $this->tela($r, $u, 'equipe', ['usuarios' => $this->n->auth->listar()]);
        }

        return $this->erro($r, 404, 'Página não encontrada', 'Esse endereço não existe.', $u);
    }

    // ---------- API ----------

    private function api(Request $r, string $caminho, array $u): Response
    {
        $get = $r->metodo === 'GET';
        $post = $r->metodo === 'POST';
        $n = $this->n;
        $p = $r->post;
        $ok = static fn (array $extra = []): Response => Response::json(['ok' => true] + $extra);

        if ($caminho === '/api/meta' && $get) {
            return $ok([
                'listas' => $n->listas->listar(),
                'etiquetas' => $n->tarefas->etiquetas(),
                'usuarios' => array_map(static fn (array $x): array => ['id' => (int) $x['id'], 'nome' => $x['nome']], $this->gestores()),
                'clientes' => array_map(static fn (array $c): array => ['id' => $c['id'], 'nome' => $c['nome']], $n->clientes->listar($u)),
                'prioridades' => array_map(static fn (int $k, string $v): array => ['id' => $k, 'nome' => $v], array_keys(Tarefas::PRIORIDADES), Tarefas::PRIORIDADES),
                'gatilhos' => Automacoes::GATILHOS,
                'hoje' => $this->agora()->format('Y-m-d'),
                'usuario' => ['id' => (int) $u['id'], 'nome' => $u['nome'], 'papel' => $u['papel']],
            ]);
        }

        // ---- rotina do dia
        if ($caminho === '/api/rotina' && $get) {
            return $ok($n->rotina->dia($u, (string) ($r->query['data'] ?? $this->agora()->format('Y-m-d')), isset($r->query['gestor']) ? (int) $r->query['gestor'] : null));
        }

        if ($post && preg_match('#^/api/rotina/(iniciar|concluir|reabrir|gerar)$#', $caminho, $m)) {
            if ($m[1] === 'gerar') {
                return $ok($n->rotina->gerarDoDia($this->agora()));
            }

            $id = (int) ($p['tarefa_id'] ?? 0);
            match ($m[1]) {
                'iniciar' => $n->rotina->iniciar($u, $id),
                'concluir' => $n->rotina->concluir($u, $id, (string) ($p['nota'] ?? '')),
                'reabrir' => $n->rotina->reabrir($u, $id),
            };

            return $ok();
        }

        // ---- tarefas
        if ($caminho === '/api/tarefas' && $get) {
            $f = array_intersect_key($r->query, array_flip(['lista_id', 'status_id', 'cliente_id', 'responsavel_id', 'prioridade', 'etiqueta', 'busca', 'de', 'ate', 'abertas', 'concluidas']));

            return $ok(['tarefas' => $n->tarefas->listar($u, $f)]);
        }

        if ($caminho === '/api/tarefas' && $post) {
            return $ok(['id' => $n->tarefas->criar($u, $this->camposTarefa($p))]);
        }

        if (preg_match('#^/api/tarefas/(\d+)(?:/(status|excluir|comentarios|itens))?$#', $caminho, $m)) {
            $id = (int) $m[1];
            $acao = $m[2] ?? '';

            if ($get && $acao === '') {
                return $ok(['tarefa' => $n->tarefas->obter($u, $id)]);
            }

            if ($post) {
                switch ($acao) {
                    case '':
                        $n->tarefas->atualizar($u, $id, $this->camposTarefa($p, false));
                        break;
                    case 'status':
                        $n->tarefas->mover($u, $id, (int) ($p['status_id'] ?? 0));
                        break;
                    case 'excluir':
                        $n->tarefas->excluir($u, $id);
                        break;
                    case 'comentarios':
                        $n->tarefas->comentar($u, $id, (string) ($p['texto'] ?? ''));
                        break;
                    case 'itens':
                        return $ok(['id' => $n->tarefas->itemAdicionar($u, $id, (string) ($p['texto'] ?? ''))]);
                }

                return $ok();
            }
        }

        if ($post && preg_match('#^/api/itens/(\d+)(?:/(excluir))?$#', $caminho, $m)) {
            ($m[2] ?? '') === 'excluir' ? $n->tarefas->itemExcluir($u, (int) $m[1]) : $n->tarefas->itemAlternar($u, (int) $m[1], ($p['feito'] ?? '') === '1' || ($p['feito'] ?? false) === true);

            return $ok();
        }

        // ---- automacoes
        if ($caminho === '/api/automacoes' && $get) {
            return $ok(['automacoes' => $n->automacoes->listar(), 'gatilhos' => Automacoes::GATILHOS]);
        }

        if ($caminho === '/api/automacoes' && $post) {
            return $ok(['id' => $n->automacoes->criar($u, $p)]);
        }

        if (preg_match('#^/api/automacoes/(\d+)(?:/(alternar|executar|excluir|execucoes))?$#', $caminho, $m)) {
            $id = (int) $m[1];
            $acao = $m[2] ?? '';

            if ($get && $acao === 'execucoes') {
                return $ok(['execucoes' => $n->automacoes->execucoes($id)]);
            }

            if ($post) {
                switch ($acao) {
                    case '':
                        $n->automacoes->atualizar($u, $id, $p);
                        break;
                    case 'alternar':
                        $n->automacoes->alternar($u, $id, ($p['ativa'] ?? '') === '1' || ($p['ativa'] ?? false) === true);
                        break;
                    case 'excluir':
                        $n->automacoes->excluir($u, $id);
                        break;
                    case 'executar':
                        return $ok($n->automacoes->executarAgora($u, $id, $this->agora()));
                }

                return $ok();
            }
        }

        return Response::json(['ok' => false, 'message' => 'Rota inexistente.'], 404);
    }

    /** Campos aceitos de uma tarefa; na edicao so entram os que vieram. */
    private function camposTarefa(array $p, bool $criacao = true): array
    {
        $campos = [];

        foreach (['titulo', 'descricao', 'prioridade', 'prazo', 'turno', 'cliente_id', 'responsavel_id', 'etiquetas'] + ($criacao ? [8 => 'lista_id'] : []) as $k) {
            if (array_key_exists($k, $p)) {
                $campos[$k] = $p[$k];
            }
        }

        if (isset($campos['etiquetas']) && is_string($campos['etiquetas'])) {
            $campos['etiquetas'] = array_filter(array_map('trim', explode(',', $campos['etiquetas'])), 'strlen');
        }

        return $campos;
    }

    // ---------- apoio ----------

    private function dadosCliente(array $post): array
    {
        if (isset($post['dias_enviado']) && !isset($post['dias'])) {
            $post['dias'] = [];
        }

        if (isset($post['plataformas_enviado']) && !isset($post['plataformas'])) {
            $post['plataformas'] = [];
        }

        return array_intersect_key($post, array_flip(['nome', 'empresa', 'descricao', 'link', 'dias', 'turno', 'status', 'gestor_id', 'contato_nome', 'contato_telefone', 'contato_email', 'plataformas', 'orcamento', 'observacoes']));
    }

    private function gestores(): array
    {
        return array_values(array_filter($this->n->auth->listar(), static fn (array $x): bool => (int) $x['ativo'] === 1));
    }

    /** Renderiza a view dentro do layout (com menu se houver usuario; centralizado se nao). */
    private function tela(Request $r, ?array $u, string $view, array $vars = [], int $status = 200): Response
    {
        $flash = $r->sessao->d['flash'] ?? null;
        unset($r->sessao->d['flash']);
        $conteudo = View::render($view, ['r' => $r, 'usuario' => $u, 'flash' => $flash] + $vars);

        return Response::html(View::render('layout', ['r' => $r, 'usuario' => $u, 'flash' => $flash, 'ativo' => $view, 'conteudo' => $conteudo, 'embutido' => $r->header('Sec-Fetch-Dest') === 'iframe']), $status);
    }

    private function erro(Request $r, int $status, string $titulo, string $msg, ?array $u = null): Response
    {
        return $this->tela($r, $u, 'erro', ['titulo' => $titulo, 'msg' => $msg], $status);
    }

    private function flash(Request $r, string $tipo, string $msg): void
    {
        $r->sessao->d['flash'] = ['tipo' => $tipo, 'msg' => $msg];
    }
}
