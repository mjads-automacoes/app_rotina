<?php

declare(strict_types=1);

final class Tarefas
{
    public const PRIORIDADES = [1 => 'Urgente', 2 => 'Alta', 3 => 'Normal', 4 => 'Baixa'];

    private ?Automacoes $automacoes = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function definirAutomacoes(Automacoes $a): void
    {
        $this->automacoes = $a;
    }

    // ---------- leitura ----------

    /** @return array{0:string,1:array} fragmento SQL (sobre o alias t) e argumentos */
    private function visivel(array $actor): array
    {
        if ($actor['papel'] === 'admin') {
            return ['1 = 1', []];
        }

        return ['(t.responsavel_id = ? OR t.criado_por = ? OR t.cliente_id IN (SELECT id FROM clientes WHERE gestor_id = ?))', [$actor['id'], $actor['id'], $actor['id']]];
    }

    private const BASE = 'SELECT t.*, s.nome AS status_nome, s.cor AS status_cor, s.tipo AS status_tipo, l.nome AS lista_nome, c.nome AS cliente_nome, u.nome AS responsavel_nome,
        (SELECT COUNT(*) FROM tarefa_itens i WHERE i.tarefa_id = t.id) AS itens_total,
        (SELECT COUNT(*) FROM tarefa_itens i WHERE i.tarefa_id = t.id AND i.feito = 1) AS itens_feitos
        FROM tarefas t JOIN status s ON s.id = t.status_id JOIN listas l ON l.id = t.lista_id
        LEFT JOIN clientes c ON c.id = t.cliente_id LEFT JOIN usuarios u ON u.id = t.responsavel_id';

    private function formatar(array $t): array
    {
        foreach (['id', 'lista_id', 'status_id', 'prioridade', 'itens_total', 'itens_feitos'] as $k) {
            $t[$k] = (int) $t[$k];
        }

        foreach (['cliente_id', 'responsavel_id', 'criado_por'] as $k) {
            $t[$k] = $t[$k] === null ? null : (int) $t[$k];
        }

        $t['prioridade_nome'] = self::PRIORIDADES[$t['prioridade']];

        return $t;
    }

    public function listar(array $actor, array $f, int $limite = 500): array
    {
        [$onde, $args] = $this->visivel($actor);
        $onde = [$onde];

        foreach (['lista_id' => 't.lista_id', 'status_id' => 't.status_id', 'cliente_id' => 't.cliente_id', 'responsavel_id' => 't.responsavel_id', 'prioridade' => 't.prioridade'] as $chave => $coluna) {
            if (isset($f[$chave]) && $f[$chave] !== '' && $f[$chave] !== null) {
                $onde[] = $coluna . ' = ?';
                $args[] = (int) $f[$chave];
            }
        }

        if (!empty($f['etiqueta'])) {
            $onde[] = 't.id IN (SELECT te.tarefa_id FROM tarefa_etiquetas te JOIN etiquetas e ON e.id = te.etiqueta_id WHERE e.nome = ? COLLATE NOCASE)';
            $args[] = (string) $f['etiqueta'];
        }

        if (!empty($f['busca'])) {
            $onde[] = "(t.titulo LIKE ? ESCAPE '\\' OR t.descricao LIKE ? ESCAPE '\\' OR c.nome LIKE ? ESCAPE '\\')";
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['busca']) . '%';
            array_push($args, $like, $like, $like);
        }

        if (!empty($f['de'])) {
            $onde[] = 't.prazo >= ?';
            $args[] = $this->dataValida((string) $f['de']);
        }

        if (!empty($f['ate'])) {
            $onde[] = 't.prazo <= ?';
            $args[] = $this->dataValida((string) $f['ate']);
        }

        if (!empty($f['abertas'])) {
            $onde[] = "s.tipo != 'fechado'";
        }

        if (!empty($f['concluidas'])) {
            $onde[] = "s.tipo = 'fechado'";
        }

        $st = $this->pdo->prepare(self::BASE . ' WHERE ' . implode(' AND ', $onde) . ' ORDER BY (t.prazo IS NULL), t.prazo, t.prioridade, t.id LIMIT ' . $limite);
        $st->execute($args);
        $tarefas = array_map([$this, 'formatar'], $st->fetchAll());
        $porId = $this->etiquetasDe(array_column($tarefas, 'id'));

        foreach ($tarefas as &$t) {
            $t['etiquetas'] = $porId[$t['id']] ?? [];
        }

        return $tarefas;
    }

    public function obter(array $actor, int $id): array
    {
        [$onde, $args] = $this->visivel($actor);
        $st = $this->pdo->prepare(self::BASE . ' WHERE t.id = ? AND ' . $onde);
        $st->execute([$id, ...$args]);
        $linha = $st->fetch();

        if ($linha === false) {
            throw new RuntimeException('Tarefa não encontrada.');
        }

        $t = $this->formatar($linha);
        $t['etiquetas'] = $this->etiquetasDe([$id])[$id] ?? [];
        $it = $this->pdo->prepare('SELECT id, texto, feito FROM tarefa_itens WHERE tarefa_id = ? ORDER BY ordem, id');
        $it->execute([$id]);
        $t['itens'] = array_map(static fn (array $i): array => ['id' => (int) $i['id'], 'texto' => $i['texto'], 'feito' => (int) $i['feito'] === 1], $it->fetchAll());
        $at = $this->pdo->prepare("SELECT a.id, a.tipo, a.texto, a.criado_em, COALESCE(u.nome, 'Sistema') AS autor FROM tarefa_atividades a LEFT JOIN usuarios u ON u.id = a.usuario_id WHERE a.tarefa_id = ? ORDER BY a.id");
        $at->execute([$id]);
        $t['atividades'] = $at->fetchAll();

        return $t;
    }

    public function etiquetas(): array
    {
        return $this->pdo->query('SELECT id, nome, cor FROM etiquetas ORDER BY nome COLLATE NOCASE')->fetchAll();
    }

    private function etiquetasDe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $st = $this->pdo->prepare('SELECT te.tarefa_id, e.id, e.nome, e.cor FROM tarefa_etiquetas te JOIN etiquetas e ON e.id = te.etiqueta_id WHERE te.tarefa_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY e.id');
        $st->execute($ids);
        $por = [];

        foreach ($st->fetchAll() as $e) {
            $por[(int) $e['tarefa_id']][] = ['id' => (int) $e['id'], 'nome' => $e['nome'], 'cor' => $e['cor']];
        }

        return $por;
    }

    public function existeOrigem(string $chave): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM tarefas WHERE origem_chave = ?');
        $st->execute([$chave]);

        return (bool) $st->fetchColumn();
    }

    // ---------- escrita ----------

    public function criar(array $actor, array $d): int
    {
        $v = $this->validar($actor, $d + ['lista_id' => 2]);
        $status = $this->primeiroStatus($v['lista_id']);
        $responsavel = array_key_exists('responsavel_id', $d) ? $v['responsavel_id'] : ($actor['id'] ?? null);
        $agora = date(DATE_ATOM);
        $sistema = !empty($actor['sistema']);

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('INSERT INTO tarefas (lista_id, cliente_id, status_id, titulo, descricao, prioridade, responsavel_id, prazo, turno, origem, origem_chave, criado_por, criado_em, atualizado_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$v['lista_id'], $v['cliente_id'], $status, $v['titulo'], $v['descricao'], $v['prioridade'], $responsavel, $v['prazo'], $v['turno'],
                    $sistema ? (string) ($d['origem'] ?? 'automacao') : 'manual', $sistema ? ($d['origem_chave'] ?? null) : null, $actor['id'] ?? null, $agora, $agora]);
            $id = (int) $this->pdo->lastInsertId();
            $this->log($id, $actor, 'criada', '');

            if (!empty($v['etiquetas'])) {
                $this->definirEtiquetas($id, $v['etiquetas']);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->automacoes?->disparar('tarefa_criada', ['tarefa' => $this->obter(Nucleo::sistema(), $id)]);

        return $id;
    }

    public function atualizar(array $actor, int $id, array $campos): void
    {
        $atual = $this->obter($actor, $id);
        $v = $this->validar($actor, $campos + $this->paraEntrada($atual), $atual['lista_id'], $atual['responsavel_id']);
        $mudancas = [];
        $sets = [];
        $args = [];

        $comparar = function (string $campo, string $tipoLog, mixed $novo, string $antesTxt, string $depoisTxt) use ($atual, $campos, &$mudancas, &$sets, &$args): void {
            if (!array_key_exists($campo, $campos) || $atual[$campo] === $novo) {
                return;
            }

            $sets[] = $campo . ' = ?';
            $args[] = $novo;
            $mudancas[] = [$tipoLog, $antesTxt . ' → ' . $depoisTxt];
        };

        $comparar('titulo', 'titulo', $v['titulo'], $atual['titulo'], $v['titulo']);
        $comparar('descricao', 'descricao', $v['descricao'], '', 'descrição editada');
        $comparar('prioridade', 'prioridade', $v['prioridade'], self::PRIORIDADES[$atual['prioridade']], self::PRIORIDADES[$v['prioridade']]);
        $comparar('prazo', 'prazo', $v['prazo'], $this->br($atual['prazo']), $this->br($v['prazo']));
        $comparar('turno', 'turno', $v['turno'], (string) ($atual['turno'] ?? '—'), (string) ($v['turno'] ?? '—'));
        $comparar('cliente_id', 'cliente', $v['cliente_id'], (string) ($atual['cliente_nome'] ?? '—'), $v['cliente_id'] === null ? '—' : (string) $this->pdo->query('SELECT nome FROM clientes WHERE id = ' . (int) $v['cliente_id'])->fetchColumn());
        $comparar('responsavel_id', 'responsavel', $v['responsavel_id'], (string) ($atual['responsavel_nome'] ?? '—'), $v['responsavel_id'] === null ? '—' : (string) $this->pdo->query('SELECT nome FROM usuarios WHERE id = ' . (int) $v['responsavel_id'])->fetchColumn());

        $this->pdo->beginTransaction();

        try {
            if ($sets !== []) {
                $this->pdo->prepare('UPDATE tarefas SET ' . implode(', ', $sets) . ', atualizado_em = ? WHERE id = ?')->execute([...$args, date(DATE_ATOM), $id]);
            }

            foreach ($mudancas as [$tipo, $texto]) {
                $this->log($id, $actor, $tipo, $texto);
            }

            if (array_key_exists('etiquetas', $campos)) {
                $antes = implode(',', array_map('mb_strtolower', array_column($atual['etiquetas'], 'nome')));
                $depois = implode(',', array_map('mb_strtolower', $v['etiquetas']));

                if ($antes !== $depois) {
                    $this->definirEtiquetas($id, $v['etiquetas']);
                    $this->log($id, $actor, 'etiquetas', implode(', ', $v['etiquetas']));
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function mover(array $actor, int $id, int $statusId): void
    {
        $t = $this->obter($actor, $id);
        $st = $this->pdo->prepare('SELECT id, nome, tipo FROM status WHERE id = ? AND lista_id = ?');
        $st->execute([$statusId, $t['lista_id']]);
        $novo = $st->fetch();

        if ($novo === false) {
            throw new InvalidArgumentException('Esse status não pertence à lista da tarefa.');
        }

        if ((int) $novo['id'] === $t['status_id']) {
            return;
        }

        $agora = date(DATE_ATOM);
        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('UPDATE tarefas SET status_id = ?, concluida_em = ?, atualizado_em = ? WHERE id = ?')
                ->execute([$statusId, $novo['tipo'] === 'fechado' ? $agora : null, $agora, $id]);
            $this->log($id, $actor, 'status', $t['status_nome'] . ' → ' . $novo['nome']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->automacoes?->disparar('status_mudou', ['tarefa' => $this->obter(Nucleo::sistema(), $id), 'de_status_id' => $t['status_id'], 'para_status_id' => $statusId, 'para_tipo' => $novo['tipo']]);
    }

    public function moverPorTipo(array $actor, int $id, string $tipo): void
    {
        $t = $this->obter($actor, $id);
        $st = $this->pdo->prepare('SELECT id FROM status WHERE lista_id = ? AND tipo = ? ORDER BY ordem LIMIT 1');
        $st->execute([$t['lista_id'], $tipo]);
        $statusId = $st->fetchColumn();

        if ($statusId === false) {
            throw new InvalidArgumentException('Essa lista não tem status do tipo ' . $tipo . '.');
        }

        $this->mover($actor, $id, (int) $statusId);
    }

    public function excluir(array $actor, int $id): void
    {
        $this->obter($actor, $id);
        $this->pdo->prepare('DELETE FROM tarefas WHERE id = ?')->execute([$id]);
    }

    public function comentar(array $actor, int $id, string $texto): void
    {
        $this->obter($actor, $id);
        $texto = trim($texto);

        if ($texto === '' || mb_strlen($texto) > 2000) {
            throw new InvalidArgumentException('Escreva o comentário (até 2000 caracteres).');
        }

        $this->log($id, $actor, 'comentario', $texto);
    }

    public function itemAdicionar(array $actor, int $id, string $texto): int
    {
        $this->obter($actor, $id);
        $texto = trim($texto);

        if ($texto === '' || mb_strlen($texto) > 200) {
            throw new InvalidArgumentException('Informe o texto do item (até 200 caracteres).');
        }

        $ordem = (int) $this->pdo->query('SELECT COALESCE(MAX(ordem), 0) + 1 FROM tarefa_itens WHERE tarefa_id = ' . $id)->fetchColumn();
        $this->pdo->prepare('INSERT INTO tarefa_itens (tarefa_id, texto, feito, ordem) VALUES (?, ?, 0, ?)')->execute([$id, $texto, $ordem]);

        return (int) $this->pdo->lastInsertId();
    }

    public function itemAlternar(array $actor, int $itemId, bool $feito): void
    {
        $this->obter($actor, $this->tarefaDoItem($itemId));
        $this->pdo->prepare('UPDATE tarefa_itens SET feito = ? WHERE id = ?')->execute([$feito ? 1 : 0, $itemId]);
    }

    public function itemExcluir(array $actor, int $itemId): void
    {
        $this->obter($actor, $this->tarefaDoItem($itemId));
        $this->pdo->prepare('DELETE FROM tarefa_itens WHERE id = ?')->execute([$itemId]);
    }

    // ---------- apoio ----------

    private function tarefaDoItem(int $itemId): int
    {
        $st = $this->pdo->prepare('SELECT tarefa_id FROM tarefa_itens WHERE id = ?');
        $st->execute([$itemId]);
        $id = $st->fetchColumn();

        if ($id === false) {
            throw new RuntimeException('Tarefa não encontrada.');
        }

        return (int) $id;
    }

    private function paraEntrada(array $t): array
    {
        return ['titulo' => $t['titulo'], 'descricao' => $t['descricao'], 'prioridade' => $t['prioridade'], 'prazo' => $t['prazo'], 'turno' => $t['turno'],
            'cliente_id' => $t['cliente_id'], 'responsavel_id' => $t['responsavel_id'], 'etiquetas' => array_column($t['etiquetas'], 'nome'), 'lista_id' => $t['lista_id']];
    }

    private function validar(array $actor, array $d, ?int $listaFixa = null, ?int $responsavelAtual = null): array
    {
        $titulo = trim((string) ($d['titulo'] ?? ''));

        if ($titulo === '' || mb_strlen($titulo) > 200) {
            throw new InvalidArgumentException('Informe o título da tarefa (até 200 caracteres).');
        }

        $listaId = $listaFixa ?? (int) ($d['lista_id'] ?? 0);

        if ($listaFixa === null) {
            $existe = $this->pdo->prepare('SELECT 1 FROM listas WHERE id = ?');
            $existe->execute([$listaId]);

            if (!$existe->fetchColumn()) {
                throw new InvalidArgumentException('Escolha uma lista válida.');
            }
        }

        $prioridade = (int) ($d['prioridade'] ?? 3);

        if (!isset(self::PRIORIDADES[$prioridade])) {
            throw new InvalidArgumentException('Escolha uma prioridade válida (1 a 4).');
        }

        $prazo = ($d['prazo'] ?? null) === null || $d['prazo'] === '' ? null : $this->dataValida((string) $d['prazo']);
        $turno = ($d['turno'] ?? null) === null || $d['turno'] === '' ? null : (string) $d['turno'];

        if ($turno !== null && !in_array($turno, Rotina::TURNOS, true)) {
            throw new InvalidArgumentException('Escolha um turno válido.');
        }

        $clienteId = ($d['cliente_id'] ?? null) === null || $d['cliente_id'] === '' ? null : (int) $d['cliente_id'];

        if ($clienteId !== null && empty($actor['sistema'])) {
            $c = $this->pdo->prepare('SELECT gestor_id FROM clientes WHERE id = ?');
            $c->execute([$clienteId]);
            $dono = $c->fetchColumn();

            if ($dono === false || ($actor['papel'] !== 'admin' && (int) $dono !== (int) $actor['id'])) {
                throw new InvalidArgumentException('Esse cliente não está disponível para você.');
            }
        }

        $responsavel = ($d['responsavel_id'] ?? null) === null || $d['responsavel_id'] === '' ? null : (int) $d['responsavel_id'];

        if ($responsavel !== null && $responsavel !== $responsavelAtual) {
            $u = $this->pdo->prepare('SELECT 1 FROM usuarios WHERE id = ? AND ativo = 1');
            $u->execute([$responsavel]);

            if (!$u->fetchColumn()) {
                throw new InvalidArgumentException('Escolha um responsável válido.');
            }
        }

        $descricao = trim((string) ($d['descricao'] ?? ''));

        if (mb_strlen($descricao) > 5000) {
            throw new InvalidArgumentException('A descrição é muito longa (máximo 5000 caracteres).');
        }

        $etiquetas = [];

        foreach ((array) ($d['etiquetas'] ?? []) as $e) {
            $e = trim((string) $e);

            if ($e !== '' && mb_strlen($e) <= 30 && !in_array(mb_strtolower($e), array_map('mb_strtolower', $etiquetas), true)) {
                $etiquetas[] = $e;
            }
        }

        return ['titulo' => $titulo, 'lista_id' => $listaId, 'prioridade' => $prioridade, 'prazo' => $prazo, 'turno' => $turno, 'cliente_id' => $clienteId,
            'responsavel_id' => $responsavel, 'descricao' => $descricao, 'etiquetas' => $etiquetas];
    }

    private function primeiroStatus(int $listaId): int
    {
        return (int) $this->pdo->query('SELECT id FROM status WHERE lista_id = ' . $listaId . " AND tipo = 'aberto' ORDER BY ordem LIMIT 1")->fetchColumn();
    }

    private function definirEtiquetas(int $tarefaId, array $nomes): void
    {
        $this->pdo->prepare('DELETE FROM tarefa_etiquetas WHERE tarefa_id = ?')->execute([$tarefaId]);
        $cores = ['#7c5cff', '#22b8cf', '#f59e0b', '#ef4444', '#22c55e', '#ec4899', '#3b82f6'];

        foreach ($nomes as $nome) {
            $st = $this->pdo->prepare('SELECT id FROM etiquetas WHERE nome = ? COLLATE NOCASE');
            $st->execute([$nome]);
            $id = $st->fetchColumn();

            if ($id === false) {
                $this->pdo->prepare('INSERT INTO etiquetas (nome, cor) VALUES (?, ?)')->execute([$nome, $cores[crc32(mb_strtolower($nome)) % count($cores)]]);
                $id = $this->pdo->lastInsertId();
            }

            $this->pdo->prepare('INSERT OR IGNORE INTO tarefa_etiquetas (tarefa_id, etiqueta_id) VALUES (?, ?)')->execute([$tarefaId, $id]);
        }
    }

    private function log(int $tarefaId, array $actor, string $tipo, string $texto): void
    {
        $this->pdo->prepare('INSERT INTO tarefa_atividades (tarefa_id, usuario_id, tipo, texto, criado_em) VALUES (?, ?, ?, ?, ?)')
            ->execute([$tarefaId, $actor['id'] ?? null, $tipo, $texto, date(DATE_ATOM)]);
    }

    private function dataValida(string $data): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('Informe um prazo válido (AAAA-MM-DD).');
        }

        return $data;
    }

    private function br(?string $data): string
    {
        return $data === null || $data === '' ? '—' : date('d/m/Y', (int) strtotime($data));
    }
}
