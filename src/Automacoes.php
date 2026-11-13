<?php

declare(strict_types=1);

/**
 * Automacoes internas ("quando X acontecer, faca Y"), no estilo do ClickUp.
 * Gatilhos: agenda (dias + hora), status_mudou, tarefa_criada, prazo_vencido.
 * Acoes: criar_tarefa, mudar_status, atribuir, definir_prioridade, adicionar_etiqueta, comentar, adicionar_item.
 */
final class Automacoes
{
    public const GATILHOS = ['agenda' => 'Em horário agendado', 'status_mudou' => 'Quando o status mudar', 'tarefa_criada' => 'Quando uma tarefa for criada', 'prazo_vencido' => 'Quando o prazo vencer'];
    public const ACOES = ['criar_tarefa', 'mudar_status', 'atribuir', 'definir_prioridade', 'adicionar_etiqueta', 'comentar', 'adicionar_item'];
    private const PROFUNDIDADE_MAXIMA = 3;

    private int $profundidade = 0;

    public function __construct(private PDO $pdo, private Tarefas $tarefas, private Clientes $clientes, private Auth $auth)
    {
    }

    // ---------- cadastro ----------

    public function listar(): array
    {
        return array_map([$this, 'formatar'], $this->pdo->query('SELECT * FROM automacoes ORDER BY id')->fetchAll());
    }

    public function obter(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM automacoes WHERE id = ?');
        $st->execute([$id]);
        $a = $st->fetch();

        return $a === false ? null : $this->formatar($a);
    }

    public function criar(array $actor, array $dados): int
    {
        $this->exigirAdmin($actor);
        $v = $this->validar($dados);
        $this->pdo->prepare('INSERT INTO automacoes (nome, ativa, gatilho, gatilho_config, condicoes, acoes, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$v['nome'], $v['ativa'], $v['gatilho'], $v['gatilho_config'], $v['condicoes'], $v['acoes'], date(DATE_ATOM)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function atualizar(array $actor, int $id, array $dados): void
    {
        $this->exigirAdmin($actor);

        if ($this->obter($id) === null) {
            throw new RuntimeException('Automação não encontrada.');
        }

        $v = $this->validar($dados);
        $this->pdo->prepare('UPDATE automacoes SET nome = ?, ativa = ?, gatilho = ?, gatilho_config = ?, condicoes = ?, acoes = ? WHERE id = ?')
            ->execute([$v['nome'], $v['ativa'], $v['gatilho'], $v['gatilho_config'], $v['condicoes'], $v['acoes'], $id]);
    }

    public function alternar(array $actor, int $id, bool $ativa): void
    {
        $this->exigirAdmin($actor);
        $this->pdo->prepare('UPDATE automacoes SET ativa = ? WHERE id = ?')->execute([$ativa ? 1 : 0, $id]);
    }

    public function excluir(array $actor, int $id): void
    {
        $this->exigirAdmin($actor);
        $this->pdo->prepare('DELETE FROM automacoes WHERE id = ?')->execute([$id]);
    }

    public function execucoes(int $id, int $limite = 20): array
    {
        $st = $this->pdo->prepare('SELECT chave, resumo, criado_em FROM automacao_execucoes WHERE automacao_id = ? ORDER BY id DESC LIMIT ' . $limite);
        $st->execute([$id]);

        return $st->fetchAll();
    }

    // ---------- execucao ----------

    /** Roda no maximo uma vez por minuto (chamado nas requisicoes); devolve o resumo ou null se foi cedo demais. */
    public function tickSeNecessario(DateTimeImmutable $agora): ?array
    {
        $ultimo = (int) ($this->pdo->query("SELECT valor FROM meta WHERE chave = 'ultimo_tick'")->fetchColumn() ?: 0);

        if ($agora->getTimestamp() - $ultimo < 60) {
            return null;
        }

        $this->pdo->prepare("INSERT INTO meta (chave, valor) VALUES ('ultimo_tick', ?) ON CONFLICT (chave) DO UPDATE SET valor = excluded.valor")->execute([(string) $agora->getTimestamp()]);

        return $this->tick($agora);
    }

    /** Dispara o que for da hora: agendas do dia e prazos vencidos. */
    public function tick(DateTimeImmutable $agora): array
    {
        $resumo = ['executadas' => 0, 'tarefas_criadas' => 0];

        foreach ($this->listar() as $a) {
            if (!$a['ativa']) {
                continue;
            }

            $r = match ($a['gatilho']) {
                'agenda' => $this->rodarAgenda($a, $agora, false),
                'prazo_vencido' => $this->rodarVencidas($a, $agora),
                default => null,
            };

            if ($r !== null) {
                $resumo['executadas']++;
                $resumo['tarefas_criadas'] += $r['tarefas_criadas'];
            }
        }

        return $resumo;
    }

    public function executarAgora(array $actor, int $id, DateTimeImmutable $agora): array
    {
        $this->exigirAdmin($actor);
        $a = $this->obter($id);

        if ($a === null) {
            throw new RuntimeException('Automação não encontrada.');
        }

        $r = match ($a['gatilho']) {
            'agenda' => $this->rodarAgenda($a, $agora, true),
            'prazo_vencido' => $this->rodarVencidas($a, $agora),
            default => throw new InvalidArgumentException('Só automações agendadas ou de prazo podem rodar agora; as demais rodam sozinhas quando o evento acontece.'),
        };

        return $r ?? ['tarefas_criadas' => 0];
    }

    /** Evento vindo das tarefas (criada / status mudou). */
    public function disparar(string $evento, array $ctx): void
    {
        if ($this->profundidade >= self::PROFUNDIDADE_MAXIMA) {
            return; // protecao: automacao que dispara outra que dispara outra...
        }

        $this->profundidade++;

        try {
            foreach ($this->listar() as $a) {
                if (!$a['ativa'] || $a['gatilho'] !== $evento) {
                    continue;
                }

                $cfg = $a['gatilho_config'];

                if ($evento === 'status_mudou'
                    && ((!empty($cfg['para_tipo']) && $cfg['para_tipo'] !== ($ctx['para_tipo'] ?? null))
                        || (!empty($cfg['para_status_id']) && (int) $cfg['para_status_id'] !== (int) ($ctx['para_status_id'] ?? 0)))) {
                    continue;
                }

                if (!$this->combina($a['condicoes'], $ctx['tarefa'], (int) ($ctx['para_status_id'] ?? 0))) {
                    continue;
                }

                $n = $this->executarAcoes($a, $ctx, new DateTimeImmutable('now'));
                $this->registrar($a['id'], 'evt:' . $ctx['tarefa']['id'] . ':' . bin2hex(random_bytes(4)), $n . ' efeito(s) na tarefa #' . $ctx['tarefa']['id']);
            }
        } finally {
            $this->profundidade--;
        }
    }

    private function rodarAgenda(array $a, DateTimeImmutable $agora, bool $forcar): ?array
    {
        $cfg = $a['gatilho_config'];
        $hoje = $agora->format('Y-m-d');

        if (!$forcar) {
            $dia = (int) $agora->format('N') <= 5 ? Rotina::DIAS[(int) $agora->format('N') - 1] : '';

            if (!in_array($dia, (array) ($cfg['dias'] ?? []), true) || $agora->format('H:i') < (string) ($cfg['hora'] ?? '00:00')) {
                return null;
            }
        }

        $chave = $forcar ? 'agenda:' . $hoje . ':manual:' . $agora->format('His') : 'agenda:' . $hoje;

        if (!$this->reservar($a['id'], $chave)) {
            return null;
        }

        try {
            $criadas = $this->executarAcoes($a, ['tarefa' => null], $agora);
        } catch (Throwable $e) {
            $this->pdo->prepare('DELETE FROM automacao_execucoes WHERE automacao_id = ? AND chave = ?')->execute([$a['id'], $chave]);
            error_log('[rotina] automação #' . $a['id'] . ' falhou: ' . $e->getMessage());

            return null;
        }

        $this->concluirRegistro($a['id'], $chave, $criadas . ' tarefa(s) criada(s)');

        return ['tarefas_criadas' => $criadas];
    }

    private function rodarVencidas(array $a, DateTimeImmutable $agora): ?array
    {
        $st = $this->pdo->prepare("SELECT t.id FROM tarefas t JOIN status s ON s.id = t.status_id WHERE s.tipo != 'fechado' AND t.prazo IS NOT NULL AND t.prazo < ? ORDER BY t.id");
        $st->execute([$agora->format('Y-m-d')]);
        $tratadas = 0;
        $criadas = 0;

        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tarefaId) {
            if (!$this->reservar($a['id'], 'venc:' . $tarefaId)) {
                continue;
            }

            $tarefa = $this->tarefas->obter(Nucleo::sistema(), (int) $tarefaId);

            if (!$this->combina($a['condicoes'], $tarefa, 0)) {
                continue;
            }

            $criadas += $this->executarAcoes($a, ['tarefa' => $tarefa], $agora);
            $tratadas++;
            $this->concluirRegistro($a['id'], 'venc:' . $tarefaId, 'tarefa #' . $tarefaId);
        }

        return $tratadas > 0 ? ['tarefas_criadas' => $criadas] : null;
    }

    /** Executa as acoes; devolve quantas tarefas foram criadas (ou efeitos aplicados, nas acoes sobre a tarefa). */
    private function executarAcoes(array $a, array $ctx, DateTimeImmutable $agora): int
    {
        $efeitos = 0;
        $sistema = Nucleo::sistema();
        $tarefa = $ctx['tarefa'] ?? null;

        foreach ($a['acoes'] as $idx => $acao) {
            switch ($acao['tipo']) {
                case 'criar_tarefa':
                    $efeitos += $this->acaoCriarTarefa($a, $idx, $acao, $tarefa, $agora);
                    break;
                case 'mudar_status':
                    if ($tarefa !== null) {
                        isset($acao['status_id']) ? $this->tarefas->mover($sistema, $tarefa['id'], (int) $acao['status_id']) : $this->tarefas->moverPorTipo($sistema, $tarefa['id'], (string) $acao['tipo_status']);
                        $efeitos++;
                    }
                    break;
                case 'atribuir':
                    if ($tarefa !== null) {
                        $usuario = ($acao['usuario_id'] ?? null) === 'gestor_do_cliente' ? $this->gestorDoCliente($tarefa['cliente_id']) : (int) $acao['usuario_id'];

                        if ($usuario !== null) {
                            $this->tarefas->atualizar($sistema, $tarefa['id'], ['responsavel_id' => $usuario]);
                            $efeitos++;
                        }
                    }
                    break;
                case 'definir_prioridade':
                    if ($tarefa !== null) {
                        $this->tarefas->atualizar($sistema, $tarefa['id'], ['prioridade' => (int) $acao['prioridade']]);
                        $efeitos++;
                    }
                    break;
                case 'adicionar_etiqueta':
                    if ($tarefa !== null) {
                        $atuais = array_column($this->tarefas->obter($sistema, $tarefa['id'])['etiquetas'], 'nome');
                        $this->tarefas->atualizar($sistema, $tarefa['id'], ['etiquetas' => [...$atuais, (string) $acao['nome']]]);
                        $efeitos++;
                    }
                    break;
                case 'comentar':
                    if ($tarefa !== null) {
                        $this->tarefas->comentar($sistema, $tarefa['id'], $this->texto((string) $acao['texto'], $tarefa, null, $agora));
                        $efeitos++;
                    }
                    break;
                case 'adicionar_item':
                    if ($tarefa !== null) {
                        $this->tarefas->itemAdicionar($sistema, $tarefa['id'], $this->texto((string) $acao['texto'], $tarefa, null, $agora));
                        $efeitos++;
                    }
                    break;
            }
        }

        $this->pdo->prepare('UPDATE automacoes SET ultima_execucao = ? WHERE id = ?')->execute([date(DATE_ATOM), $a['id']]);

        return $efeitos;
    }

    private function acaoCriarTarefa(array $a, int $idx, array $acao, ?array $tarefa, DateTimeImmutable $agora): int
    {
        $hoje = $agora->format('Y-m-d');
        $por = $tarefa === null ? ($acao['por'] ?? 'unica') : 'unica';
        $prazo = $this->prazo($acao['prazo'] ?? null, $agora);
        $criadas = 0;
        $base = ['lista_id' => (int) $acao['lista_id'], 'prioridade' => (int) ($acao['prioridade'] ?? 3), 'prazo' => $prazo, 'origem' => 'automacao'];
        $alvos = [];

        if ($por === 'cliente_agendado') {
            $dia = (int) $agora->format('N') <= 5 ? Rotina::DIAS[(int) $agora->format('N') - 1] : null;

            if ($dia !== null) {
                // gestor desativado nao recebe tarefas: o cliente dele fica de fora ate ser reatribuido
                $st = $this->pdo->prepare("SELECT c.id, c.nome, c.gestor_id, c.turno FROM clientes c JOIN usuarios u ON u.id = c.gestor_id AND u.ativo = 1
                    WHERE c.status = 'ativo' AND (',' || c.dias || ',') LIKE ? ORDER BY c.nome COLLATE NOCASE, c.id");
                $st->execute(['%,' . $dia . ',%']);

                foreach ($st->fetchAll() as $c) {
                    $alvos[] = ['chave' => 'c' . $c['id'], 'cliente_id' => (int) $c['id'], 'cliente' => $c['nome'], 'responsavel_id' => (int) $c['gestor_id'], 'turno_cliente' => $c['turno'], 'gestor' => null];
                }
            }
        } elseif ($por === 'gestor') {
            foreach ($this->auth->listar() as $u) {
                if ((int) $u['ativo'] === 1) {
                    $alvos[] = ['chave' => 'g' . $u['id'], 'cliente_id' => null, 'cliente' => '', 'responsavel_id' => (int) $u['id'], 'turno_cliente' => null, 'gestor' => $u['nome']];
                }
            }
        } else {
            $resp = $acao['responsavel'] ?? null;
            $responsavelId = $resp === 'gatilho' ? ($tarefa['responsavel_id'] ?? null) : ($resp === 'gestor_do_cliente' ? $this->gestorDoCliente($tarefa['cliente_id'] ?? null) : ($resp === null ? null : (int) $resp));
            $alvos[] = ['chave' => '', 'cliente_id' => $tarefa['cliente_id'] ?? null, 'cliente' => (string) ($tarefa['cliente_nome'] ?? ''), 'responsavel_id' => $responsavelId, 'turno_cliente' => null, 'gestor' => $tarefa['responsavel_nome'] ?? null];
        }

        foreach ($alvos as $alvo) {
            $chave = $tarefa === null ? 'auto:' . $a['id'] . ':' . $idx . ':' . $hoje . ($alvo['chave'] !== '' ? ':' . $alvo['chave'] : '') : null;

            if ($chave !== null && $this->tarefas->existeOrigem($chave)) {
                continue;
            }

            $turno = ($acao['turno'] ?? null) === 'cliente' ? $alvo['turno_cliente'] : ($acao['turno'] ?? null);
            $titulo = $this->texto((string) $acao['titulo'], $tarefa, $alvo, $agora);
            $this->tarefas->criar(Nucleo::sistema(), $base + ['titulo' => mb_substr($titulo, 0, 200), 'cliente_id' => $alvo['cliente_id'], 'responsavel_id' => $alvo['responsavel_id'], 'turno' => $turno, 'origem_chave' => $chave]);
            $criadas++;
        }

        return $criadas;
    }

    // ---------- apoio ----------

    private function gestorDoCliente(?int $clienteId): ?int
    {
        if ($clienteId === null) {
            return null;
        }

        $st = $this->pdo->prepare('SELECT gestor_id FROM clientes WHERE id = ?');
        $st->execute([$clienteId]);
        $g = $st->fetchColumn();

        return $g === false ? null : (int) $g;
    }

    private function prazo(mixed $valor, DateTimeImmutable $agora): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor === 'hoje') {
            return $agora->format('Y-m-d');
        }

        if (preg_match('/^\+(\d{1,3})$/', (string) $valor, $m)) {
            return $agora->modify('+' . $m[1] . ' days')->format('Y-m-d');
        }

        return (string) $valor;
    }

    private function texto(string $modelo, ?array $tarefa, ?array $alvo, DateTimeImmutable $agora): string
    {
        return strtr($modelo, [
            '{cliente}' => (string) ($alvo['cliente'] ?? $tarefa['cliente_nome'] ?? ''),
            '{tarefa}' => (string) ($tarefa['titulo'] ?? ''),
            '{data}' => $agora->format('d/m/Y'),
            '{gestor}' => (string) ($alvo['gestor'] ?? $tarefa['responsavel_nome'] ?? ''),
            '{usuario}' => 'Sistema',
        ]);
    }

    private function combina(array $cond, array $tarefa, int $statusId): bool
    {
        foreach (['lista_id', 'cliente_id', 'prioridade', 'responsavel_id'] as $k) {
            if (!empty($cond[$k]) && (int) $cond[$k] !== (int) ($tarefa[$k] ?? 0)) {
                return false;
            }
        }

        return empty($cond['status_id']) || $statusId === 0 || (int) $cond['status_id'] === $statusId;
    }

    /** Marca a execucao (chave unica por automacao); false se ja tinha. */
    private function reservar(int $automacaoId, string $chave): bool
    {
        $st = $this->pdo->prepare('INSERT OR IGNORE INTO automacao_execucoes (automacao_id, chave, resumo, criado_em) VALUES (?, ?, ?, ?)');
        $st->execute([$automacaoId, $chave, '', date(DATE_ATOM)]);

        return $st->rowCount() === 1;
    }

    private function concluirRegistro(int $automacaoId, string $chave, string $resumo): void
    {
        $this->pdo->prepare('UPDATE automacao_execucoes SET resumo = ? WHERE automacao_id = ? AND chave = ?')->execute([$resumo, $automacaoId, $chave]);
    }

    private function registrar(int $automacaoId, string $chave, string $resumo): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO automacao_execucoes (automacao_id, chave, resumo, criado_em) VALUES (?, ?, ?, ?)')->execute([$automacaoId, $chave, $resumo, date(DATE_ATOM)]);
    }

    private function exigirAdmin(array $actor): void
    {
        if ($actor['papel'] !== 'admin') {
            throw new AcessoNegado('Só o administrador gerencia as automações.');
        }
    }

    private function formatar(array $a): array
    {
        return ['id' => (int) $a['id'], 'nome' => $a['nome'], 'ativa' => (int) $a['ativa'] === 1, 'gatilho' => $a['gatilho'],
            'gatilho_config' => json_decode((string) $a['gatilho_config'], true) ?: [], 'condicoes' => json_decode((string) $a['condicoes'], true) ?: [],
            'acoes' => json_decode((string) $a['acoes'], true) ?: [], 'ultima_execucao' => $a['ultima_execucao'], 'criado_em' => $a['criado_em']];
    }

    private function validar(array $d): array
    {
        $nome = trim((string) ($d['nome'] ?? ''));

        if ($nome === '' || mb_strlen($nome) > 120) {
            throw new InvalidArgumentException('Informe o nome da automação (até 120 caracteres).');
        }

        $gatilho = (string) ($d['gatilho'] ?? '');

        if (!isset(self::GATILHOS[$gatilho])) {
            throw new InvalidArgumentException('Escolha um gatilho válido.');
        }

        $cfgEntrada = (array) ($d['gatilho_config'] ?? []);
        $cfg = [];

        if ($gatilho === 'agenda') {
            $dias = array_values(array_intersect(Rotina::DIAS, (array) ($cfgEntrada['dias'] ?? [])));

            foreach ((array) ($cfgEntrada['dias'] ?? []) as $dia) {
                if (!in_array($dia, Rotina::DIAS, true)) {
                    throw new InvalidArgumentException('O dia "' . $dia . '" não é válido (use segunda a sexta).');
                }
            }

            if ($dias === []) {
                throw new InvalidArgumentException('Escolha pelo menos um dia da semana.');
            }

            $hora = (string) ($cfgEntrada['hora'] ?? '');

            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
                throw new InvalidArgumentException('Informe a hora no formato HH:MM.');
            }

            $cfg = ['dias' => $dias, 'hora' => $hora];
        } elseif ($gatilho === 'status_mudou') {
            if (!empty($cfgEntrada['para_tipo'])) {
                if (!in_array($cfgEntrada['para_tipo'], Listas::TIPOS, true)) {
                    throw new InvalidArgumentException('Escolha um tipo de status válido.');
                }

                $cfg['para_tipo'] = $cfgEntrada['para_tipo'];
            }

            if (!empty($cfgEntrada['para_status_id'])) {
                $cfg['para_status_id'] = (int) $cfgEntrada['para_status_id'];
            }
        }

        $cond = [];

        foreach (['lista_id', 'cliente_id', 'prioridade', 'responsavel_id', 'status_id'] as $k) {
            if (!empty($d['condicoes'][$k])) {
                $cond[$k] = (int) $d['condicoes'][$k];
            }
        }

        if (!empty($cond['prioridade']) && !isset(Tarefas::PRIORIDADES[$cond['prioridade']])) {
            throw new InvalidArgumentException('Escolha uma prioridade válida (1 a 4).');
        }

        $acoes = [];

        foreach ((array) ($d['acoes'] ?? []) as $acao) {
            $acoes[] = $this->validarAcao((array) $acao);
        }

        if ($acoes === []) {
            throw new InvalidArgumentException('Adicione ao menos uma ação.');
        }

        return ['nome' => $nome, 'ativa' => ($d['ativa'] ?? true) ? 1 : 0, 'gatilho' => $gatilho, 'gatilho_config' => json_encode($cfg, JSON_UNESCAPED_UNICODE),
            'condicoes' => json_encode($cond, JSON_UNESCAPED_UNICODE), 'acoes' => json_encode($acoes, JSON_UNESCAPED_UNICODE)];
    }

    private function validarAcao(array $a): array
    {
        $tipo = (string) ($a['tipo'] ?? '');

        if (!in_array($tipo, self::ACOES, true)) {
            throw new InvalidArgumentException('Essa ação não existe: ' . $tipo . '.');
        }

        $texto = static function (string $k, int $max) use ($a): string {
            $v = trim((string) ($a[$k] ?? ''));

            if ($v === '' || mb_strlen($v) > $max) {
                throw new InvalidArgumentException('Preencha o texto da ação (até ' . $max . ' caracteres).');
            }

            return $v;
        };

        switch ($tipo) {
            case 'criar_tarefa':
                $lista = $this->pdo->prepare('SELECT 1 FROM listas WHERE id = ?');
                $lista->execute([(int) ($a['lista_id'] ?? 0)]);

                if (!$lista->fetchColumn()) {
                    throw new InvalidArgumentException('Escolha uma lista válida na ação.');
                }

                $por = (string) ($a['por'] ?? 'unica');

                if (!in_array($por, ['unica', 'cliente_agendado', 'gestor'], true)) {
                    throw new InvalidArgumentException('Escolha para quem criar a tarefa.');
                }

                $turno = ($a['turno'] ?? null) === null || $a['turno'] === '' ? null : (string) $a['turno'];

                if ($turno !== null && $turno !== 'cliente' && !in_array($turno, Rotina::TURNOS, true)) {
                    throw new InvalidArgumentException('Escolha um turno válido.');
                }

                $prioridade = (int) ($a['prioridade'] ?? 3);

                if (!isset(Tarefas::PRIORIDADES[$prioridade])) {
                    throw new InvalidArgumentException('Escolha uma prioridade válida (1 a 4).');
                }

                $prazo = ($a['prazo'] ?? null) === null || $a['prazo'] === '' ? null : (string) $a['prazo'];

                if ($prazo !== null && $prazo !== 'hoje' && !preg_match('/^\+\d{1,3}$/', $prazo) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prazo)) {
                    throw new InvalidArgumentException('Prazo inválido (use hoje, +N ou uma data AAAA-MM-DD).');
                }

                $resp = $a['responsavel'] ?? null;

                return ['tipo' => $tipo, 'lista_id' => (int) $a['lista_id'], 'titulo' => $texto('titulo', 200), 'por' => $por, 'turno' => $turno, 'prazo' => $prazo,
                    'prioridade' => $prioridade, 'responsavel' => in_array($resp, [null, '', 'gatilho', 'gestor_do_cliente'], true) ? ($resp === '' ? null : $resp) : (int) $resp];
            case 'mudar_status':
                if (!empty($a['status_id'])) {
                    $s = $this->pdo->prepare('SELECT 1 FROM status WHERE id = ?');
                    $s->execute([(int) $a['status_id']]);

                    if (!$s->fetchColumn()) {
                        throw new InvalidArgumentException('Escolha um status válido na ação.');
                    }

                    return ['tipo' => $tipo, 'status_id' => (int) $a['status_id']];
                }

                if (!in_array($a['tipo_status'] ?? '', Listas::TIPOS, true)) {
                    throw new InvalidArgumentException('Escolha um status válido na ação.');
                }

                return ['tipo' => $tipo, 'tipo_status' => $a['tipo_status']];
            case 'atribuir':
                if (($a['usuario_id'] ?? null) === 'gestor_do_cliente') {
                    return ['tipo' => $tipo, 'usuario_id' => 'gestor_do_cliente'];
                }

                if ($this->auth->buscar((int) ($a['usuario_id'] ?? 0)) === null) {
                    throw new InvalidArgumentException('Escolha um responsável válido na ação.');
                }

                return ['tipo' => $tipo, 'usuario_id' => (int) $a['usuario_id']];
            case 'definir_prioridade':
                if (!isset(Tarefas::PRIORIDADES[(int) ($a['prioridade'] ?? 0)])) {
                    throw new InvalidArgumentException('Escolha uma prioridade válida (1 a 4).');
                }

                return ['tipo' => $tipo, 'prioridade' => (int) $a['prioridade']];
            case 'adicionar_etiqueta':
                return ['tipo' => $tipo, 'nome' => $texto('nome', 30)];
            case 'comentar':
                return ['tipo' => $tipo, 'texto' => $texto('texto', 2000)];
            default:
                return ['tipo' => $tipo, 'texto' => $texto('texto', 200)];
        }
    }
}
