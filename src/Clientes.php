<?php

declare(strict_types=1);

final class Clientes
{
    public const PLATAFORMAS = ['google', 'meta', 'tiktok', 'linkedin', 'outros'];
    /** Observacoes guardam a descricao inteira do ClickUp (documento de onboarding), por isso o limite e alto. */
    public const OBSERVACOES_MAX = 20000;
    /** Colunas do kanban de clientes, na ordem (pedido do dono, 24/11/2026). Ativo, Alerta e Churn entram na rotina automatica; Novo fica de fora. */
    public const SITUACOES = ['novo', 'ativo', 'alerta', 'churn'];
    /**
     * Entregavel do cliente: uma etiqueta colorida escolhida numa lista que o dono edita (nome, cor, ordem), como no ClickUp.
     * A lista fica na tabela meta (chave "entregaveis"); o cliente guarda o id da etiqueta. Pedido do dono, 24/11/2026.
     */
    public const CORES_ENTREGAVEL = ['#8b1a1a', '#e03131', '#e8590c', '#f5c400', '#2ecc71', '#14b8a6', '#7aa7f7', '#2f6fe0', '#1f3a8a', '#9b59b6', '#4b2bbd', '#f0386b', '#6b7785', '#3a3f46'];
    private const ENTREGAVEIS_PADRAO = [
        ['id' => 'completo', 'nome' => 'Completo', 'cor' => '#8b1a1a'],
        ['id' => 'trafego', 'nome' => 'Tráfego', 'cor' => '#2ecc71'],
        ['id' => 'trafego_lp_crm', 'nome' => 'Tráfego, LP e CRM', 'cor' => '#7aa7f7'],
        ['id' => 'trafego_social', 'nome' => 'Tráfego e Social Media', 'cor' => '#f0386b'],
        ['id' => 'trafego_social_lp', 'nome' => 'Tráfego, Social Media e LP', 'cor' => '#9b59b6'],
        ['id' => 'trafego_social_crm', 'nome' => 'Tráfego, Social Media e CRM', 'cor' => '#e8590c'],
        ['id' => 'trafego_crm', 'nome' => 'Tráfego e CRM', 'cor' => '#6b7785'],
        ['id' => 'trafego_rebranding_crm', 'nome' => 'Tráfego, Rebranding e CRM', 'cor' => '#f5c400'],
        ['id' => 'completo_video', 'nome' => 'Completo + Video Maker', 'cor' => '#4b2bbd'],
        ['id' => 'trafego_lp', 'nome' => 'Tráfego + LP', 'cor' => '#1f3a8a'],
        ['id' => 'trafego_video_social', 'nome' => 'Tráfego + Videomaker + Social Media', 'cor' => '#3a3f46'],
        ['id' => 'bot_disparos', 'nome' => 'Bot-Disparos', 'cor' => '#2f6fe0'],
    ];

    /** Etiquetas de entregavel, na ordem escolhida. */
    public function entregaveis(): array
    {
        $st = $this->pdo->prepare('SELECT valor FROM meta WHERE chave = ?');
        $st->execute(['entregaveis']);
        $salvo = json_decode((string) ($st->fetchColumn() ?: ''), true);

        return is_array($salvo) ? $salvo : self::ENTREGAVEIS_PADRAO;
    }

    /** Troca a lista inteira (renomear, mudar cor, reordenar, criar, excluir). Cliente com etiqueta excluida fica sem entregavel. */
    public function salvarEntregaveis(array $actor, array $lista): array
    {
        if ($actor['papel'] !== 'admin') {
            throw new AcessoNegado('Só o administrador edita a lista de entregáveis.');
        }

        if (count($lista) > 60) {
            throw new InvalidArgumentException('Muitos entregáveis (máximo 60).');
        }

        $existentes = array_column($this->entregaveis(), 'id');
        $nova = [];
        $usados = [];

        foreach ($lista as $item) {
            $nome = trim((string) ($item['nome'] ?? ''));
            $cor = strtolower((string) ($item['cor'] ?? ''));
            $id = (string) ($item['id'] ?? '');

            if ($nome === '' || mb_strlen($nome) > 60) {
                throw new InvalidArgumentException('Escreva o nome do entregável (até 60 caracteres).');
            }

            if (!in_array($cor, self::CORES_ENTREGAVEL, true)) {
                throw new InvalidArgumentException('Escolha uma das cores da lista.');
            }

            if ($id === '' || !in_array($id, $existentes, true) || isset($usados[$id])) {
                $id = 'e' . bin2hex(random_bytes(4));
            }

            $usados[$id] = true;
            $nova[] = ['id' => $id, 'nome' => $nome, 'cor' => $cor];
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('INSERT OR REPLACE INTO meta (chave, valor) VALUES (?, ?)')->execute(['entregaveis', json_encode($nova, JSON_UNESCAPED_UNICODE)]);
            $removidos = array_diff($existentes, array_keys($usados));

            if ($removidos !== []) {
                $st = $this->pdo->prepare("UPDATE clientes SET entregaveis = '' WHERE entregaveis = ?");

                foreach ($removidos as $id) {
                    $st->execute([$id]);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $nova;
    }

    /** Estados do Brasil para o campo "Estado" da ficha. */
    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
    public const SITUACAO_NOME = ['novo' => 'Novo', 'ativo' => 'Ativo', 'alerta' => 'Alerta', 'churn' => 'Churn'];
    /** Notas espelham os comentarios do ClickUp, que podem ser longos (planos de acao). */
    public const NOTA_MAX = 20000;

    public function __construct(private PDO $pdo)
    {
    }

    /** Clientes que o usuario pode ver: gestor = so os dele; admin = todos (ou de um gestor). */
    public function listar(array $actor, ?int $gestorId = null): array
    {
        $sql = 'SELECT * FROM clientes';
        $args = [];

        if ($actor['papel'] !== 'admin') {
            $sql .= ' WHERE gestor_id = ?';
            $args[] = $actor['id'];
        } elseif ($gestorId !== null) {
            $sql .= ' WHERE gestor_id = ?';
            $args[] = $gestorId;
        }

        $st = $this->pdo->prepare($sql . ' ORDER BY nome COLLATE NOCASE, id');
        $st->execute($args);

        return array_map([$this, 'formatar'], $st->fetchAll());
    }

    public function buscar(array $actor, int $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM clientes WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch();

        if ($c === false || ($actor['papel'] !== 'admin' && (int) $c['gestor_id'] !== (int) $actor['id'])) {
            throw new RuntimeException('Cliente não encontrado.');
        }

        return $this->formatar($c);
    }

    public function buscarPorClickup(int $gestorId, string $clickupId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM clientes WHERE gestor_id = ? AND clickup_id = ?');
        $st->execute([$gestorId, $clickupId]);
        $c = $st->fetch();

        return $c === false ? null : $this->formatar($c);
    }

    public function criar(array $actor, array $dados): int
    {
        $v = $this->validar($dados);
        $gestorId = (int) $actor['id'];

        if ($actor['papel'] === 'admin' && isset($dados['gestor_id']) && (int) $dados['gestor_id'] > 0) {
            $gestorId = $this->responsavelValido((int) $dados['gestor_id']);
        }

        $agora = date(DATE_ATOM);
        $this->pdo->prepare('INSERT INTO clientes (gestor_id, nome, empresa, descricao, link, dias, turno, status, contato_nome, contato_telefone, contato_email, plataformas, orcamento, observacoes, clickup_id, clickup_dados, criado_em, atualizado_em, cnpj, cidade, estado, entregaveis)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$gestorId, $v['nome'], $v['empresa'], $v['descricao'], $v['link'], implode(',', $v['dias']), $v['turno'], $v['status'], $v['contato_nome'], $v['contato_telefone'],
                $v['contato_email'], implode(',', $v['plataformas']), $v['orcamento'], $v['observacoes'], $dados['clickup_id'] ?? null, $dados['clickup_dados'] ?? null, $agora, $agora, $v['cnpj'], $v['cidade'], $v['estado'], $v['entregaveis']]);

        return (int) $this->pdo->lastInsertId();
    }

    public function atualizar(array $actor, int $id, array $dados): void
    {
        $atual = $this->buscar($actor, $id);
        $v = $this->validar($dados + $atual);
        $gestorId = (int) $atual['gestor_id'];

        if ($actor['papel'] === 'admin' && isset($dados['gestor_id']) && (int) $dados['gestor_id'] > 0) {
            $gestorId = $this->responsavelValido((int) $dados['gestor_id']);
        }

        $this->pdo->prepare('UPDATE clientes SET gestor_id = ?, nome = ?, empresa = ?, descricao = ?, link = ?, dias = ?, turno = ?, status = ?, contato_nome = ?, contato_telefone = ?, contato_email = ?,
            plataformas = ?, orcamento = ?, observacoes = ?, clickup_dados = ?, atualizado_em = ?, cnpj = ?, cidade = ?, estado = ?, entregaveis = ? WHERE id = ?')
            ->execute([$gestorId, $v['nome'], $v['empresa'], $v['descricao'], $v['link'], implode(',', $v['dias']), $v['turno'], $v['status'], $v['contato_nome'], $v['contato_telefone'], $v['contato_email'],
                implode(',', $v['plataformas']), $v['orcamento'], $v['observacoes'], array_key_exists('clickup_dados', $dados) ? $dados['clickup_dados'] : $atual['clickup_dados'], date(DATE_ATOM), $v['cnpj'], $v['cidade'], $v['estado'], $v['entregaveis'], $id]);
    }

    /** O responsavel do cliente precisa ser um usuario ativo da equipe. */
    private function responsavelValido(int $id): int
    {
        $existe = $this->pdo->prepare("SELECT 1 FROM usuarios WHERE id = ? AND papel IN ('gestor','admin')");
        $existe->execute([$id]);

        if (!$existe->fetchColumn()) {
            throw new InvalidArgumentException('Escolha um responsável válido.');
        }

        return $id;
    }

    public function excluir(array $actor, int $id): void
    {
        $this->buscar($actor, $id);
        // pedido do dono (17/11/2026): as tarefas em aberto do cliente vao junto; as concluidas ficam, sem cliente, para o historico
        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare("DELETE FROM tarefas WHERE cliente_id = ? AND status_id IN (SELECT id FROM status WHERE tipo != 'fechado')")->execute([$id]);
            $this->pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ---------- notas ----------

    /** Mais recente primeiro. */
    public function notas(array $actor, int $id): array
    {
        $this->buscar($actor, $id);
        $st = $this->pdo->prepare('SELECT id, autor, texto, origem, criado_em FROM cliente_notas WHERE cliente_id = ? AND excluida = 0 ORDER BY id DESC');
        $st->execute([$id]);
        $notas = $st->fetchAll();

        foreach ($notas as &$n) {
            $n['minha'] = $this->podeMexerNaNota($actor, $n);
        }

        return $notas;
    }

    /** Quem escreveu a nota (ou o administrador) pode editar e excluir. Pedido do dono, 24/11/2026. */
    private function podeMexerNaNota(array $actor, array $nota): bool
    {
        return $actor['papel'] === 'admin' || ((string) $nota['autor'] !== '' && (string) $nota['autor'] === (string) ($actor['nome'] ?? ''));
    }

    private function notaDoCliente(array $actor, int $clienteId, int $notaId): array
    {
        $this->buscar($actor, $clienteId);
        $st = $this->pdo->prepare('SELECT id, autor, texto, origem FROM cliente_notas WHERE id = ? AND cliente_id = ? AND excluida = 0');
        $st->execute([$notaId, $clienteId]);
        $nota = $st->fetch();

        if ($nota === false) {
            throw new RuntimeException('Nota não encontrada.');
        }

        if (!$this->podeMexerNaNota($actor, $nota)) {
            throw new AcessoNegado('Só quem escreveu a nota pode mudar ou excluir.');
        }

        return $nota;
    }

    public function notaEditar(array $actor, int $clienteId, int $notaId, string $texto): void
    {
        $this->notaDoCliente($actor, $clienteId, $notaId);
        $texto = trim($texto);

        if ($texto === '' || mb_strlen($texto) > self::NOTA_MAX) {
            throw new InvalidArgumentException('Escreva a nota (até ' . self::NOTA_MAX . ' caracteres).');
        }

        $this->pdo->prepare('UPDATE cliente_notas SET texto = ? WHERE id = ?')->execute([$texto, $notaId]);
    }

    public function notaExcluir(array $actor, int $clienteId, int $notaId): void
    {
        $this->notaDoCliente($actor, $clienteId, $notaId);
        $this->pdo->prepare('UPDATE cliente_notas SET excluida = 1 WHERE id = ?')->execute([$notaId]);
    }

    public function notaAdicionar(array $actor, int $id, string $texto): void
    {
        $this->buscar($actor, $id);
        $texto = trim($texto);

        if ($texto === '' || mb_strlen($texto) > self::NOTA_MAX) {
            throw new InvalidArgumentException('Escreva a nota (até ' . self::NOTA_MAX . ' caracteres).');
        }

        $this->pdo->prepare("INSERT INTO cliente_notas (cliente_id, autor, texto, origem, criado_em) VALUES (?, ?, ?, 'manual', ?)")->execute([$id, (string) ($actor['nome'] ?? ''), $texto, date(DATE_ATOM)]);
    }

    /** Nota vinda de fora (ClickUp). Devolve true se era nova. */
    public function notaImportada(int $clienteId, string $autor, string $texto, string $origem, string $origemId, string $criadoEm): bool
    {
        $st = $this->pdo->prepare('INSERT OR IGNORE INTO cliente_notas (cliente_id, autor, texto, origem, origem_id, criado_em) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$clienteId, mb_substr($autor, 0, 120), mb_substr($texto, 0, self::NOTA_MAX), $origem, $origemId, $criadoEm]);

        return $st->rowCount() === 1;
    }

    // ---------- apoio ----------

    private function validar(array $d): array
    {
        $nome = trim((string) ($d['nome'] ?? ''));

        if ($nome === '' || mb_strlen($nome) > 120) {
            throw new InvalidArgumentException('Informe o nome do cliente (até 120 caracteres).');
        }

        $dias = $d['dias'] ?? [];
        $dias = is_string($dias) ? array_filter(explode(',', $dias), 'strlen') : (array) $dias;

        foreach ($dias as $dia) {
            if (!in_array($dia, Rotina::DIAS, true)) {
                throw new InvalidArgumentException('O dia "' . $dia . '" não é válido (use segunda a sexta).');
            }
        }

        $dias = array_values(array_intersect(Rotina::DIAS, $dias));
        $turno = (string) ($d['turno'] ?? 'manha');

        if (!in_array($turno, Rotina::TURNOS, true)) {
            throw new InvalidArgumentException('Escolha um turno válido.');
        }

        $status = (string) ($d['status'] ?? 'ativo');

        if (!in_array($status, self::SITUACOES, true)) {
            throw new InvalidArgumentException('Escolha um status válido.');
        }

        $link = trim((string) ($d['link'] ?? ''));

        if ($link !== '' && !preg_match('#^https?://#i', $link)) {
            throw new InvalidArgumentException('O link precisa começar com http:// ou https://.');
        }

        $email = trim((string) ($d['contato_email'] ?? ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Informe um e-mail de contato válido.');
        }

        $plataformas = $d['plataformas'] ?? [];
        $plataformas = is_string($plataformas) ? array_filter(explode(',', $plataformas), 'strlen') : (array) $plataformas;

        foreach ($plataformas as $p) {
            if (!in_array($p, self::PLATAFORMAS, true)) {
                throw new InvalidArgumentException('A plataforma "' . $p . '" não é válida.');
            }
        }

        $plataformas = array_values(array_intersect(self::PLATAFORMAS, $plataformas));

        // entregavel: uma etiqueta da lista (ou nenhuma); valor antigo que nao existe mais vira "sem entregavel"
        $entregaveis = $d['entregaveis'] ?? '';
        $entregaveis = is_array($entregaveis) ? (string) ($entregaveis[0] ?? '') : trim((string) $entregaveis);

        if ($entregaveis !== '' && !in_array($entregaveis, array_column($this->entregaveis(), 'id'), true)) {
            if (array_key_exists('entregaveis', $d) && !array_key_exists('id', $d)) {
                throw new InvalidArgumentException('Escolha um entregável da lista.');
            }

            $entregaveis = '';
        }

        $curtos = [];

        foreach (['empresa' => 120, 'descricao' => 500, 'contato_nome' => 120, 'contato_telefone' => 30, 'orcamento' => 60] as $campo => $max) {
            $valor = trim((string) ($d[$campo] ?? ''));

            if (mb_strlen($valor) > $max) {
                throw new InvalidArgumentException('O campo ' . $campo . ' é muito longo (máximo ' . $max . ' caracteres).');
            }

            $curtos[$campo] = $valor;
        }

        $observacoes = trim((string) ($d['observacoes'] ?? ''));

        if (mb_strlen($observacoes) > self::OBSERVACOES_MAX || mb_strlen($link) > 500) {
            throw new InvalidArgumentException('Texto muito longo nas observações ou no link (observações até ' . self::OBSERVACOES_MAX . ' caracteres).');
        }

        // CNPJ com 14 numeros, guardado ja formatado (00.000.000/0000-00); cidade livre; estado da lista
        $cnpj = preg_replace('/\D/', '', (string) ($d['cnpj'] ?? ''));

        if ($cnpj !== '' && strlen($cnpj) !== 14) {
            throw new InvalidArgumentException('O CNPJ precisa ter 14 números.');
        }

        $cnpj = $cnpj === '' ? '' : substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . substr($cnpj, 5, 3) . '/' . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12, 2);
        $cidade = trim((string) ($d['cidade'] ?? ''));

        if (mb_strlen($cidade) > 80) {
            throw new InvalidArgumentException('O nome da cidade é muito longo (máximo 80 caracteres).');
        }

        $estado = strtoupper(trim((string) ($d['estado'] ?? '')));

        if ($estado !== '' && !in_array($estado, self::UFS, true)) {
            throw new InvalidArgumentException('Escolha um estado válido.');
        }

        return ['entregaveis' => $entregaveis, 'cnpj' => $cnpj, 'cidade' => $cidade, 'estado' => $estado, 'nome' => $nome, 'link' => $link, 'dias' => $dias, 'turno' => $turno, 'status' => $status, 'contato_email' => $email, 'plataformas' => $plataformas, 'observacoes' => $observacoes] + $curtos;
    }

    private function formatar(array $c): array
    {
        $c['id'] = (int) $c['id'];
        $c['gestor_id'] = (int) $c['gestor_id'];
        $c['dias'] = $c['dias'] === '' ? [] : explode(',', (string) $c['dias']);
        $c['plataformas'] = $c['plataformas'] === '' ? [] : explode(',', (string) $c['plataformas']);
        $c['entregaveis'] = (string) ($c['entregaveis'] ?? '');

        return $c;
    }
}
