<?php

declare(strict_types=1);

/** A rotina do dia: tarefas com turno e prazo na semana, separadas em checklist (sem cliente) e clientes. */
final class Rotina
{
    public const DIAS = ['segunda', 'terca', 'quarta', 'quinta', 'sexta'];
    public const TURNOS = ['manha', 'tarde', 'fechamento'];

    public function __construct(private PDO $pdo, private Tarefas $tarefas, private Automacoes $automacoes)
    {
    }

    /** Segunda a sexta da semana da data; sabado e domingo mostram a semana seguinte. [data => dia]. */
    public static function semana(string $data): array
    {
        $d = self::data($data);
        $n = (int) $d->format('N');
        $segunda = $n >= 6 ? $d->modify('next monday') : $d->modify('-' . ($n - 1) . ' days');
        $semana = [];

        foreach (self::DIAS as $i => $dia) {
            $semana[$segunda->modify('+' . $i . ' days')->format('Y-m-d')] = $dia;
        }

        return $semana;
    }

    public function dia(array $actor, string $data, ?int $gestorId = null): array
    {
        $alvo = $actor['papel'] === 'admin' && $gestorId !== null && $gestorId > 0 ? $gestorId : (int) $actor['id'];
        $semana = self::semana($data);
        $dias = [];

        foreach ($semana as $dataDia => $dia) {
            $dias[$dataDia] = ['dia' => $dia, 'total' => 0, 'feitas' => 0, 'turnos' => array_fill_keys(self::TURNOS, ['checklist' => [], 'clientes' => []])];
        }

        $tarefas = $this->tarefas->listar($actor, ['responsavel_id' => $alvo, 'de' => array_key_first($semana), 'ate' => array_key_last($semana)], 2000);
        $clientes = $this->pdo->query('SELECT id, nome, descricao, link FROM clientes')->fetchAll(PDO::FETCH_UNIQUE);
        // ordem: a escolhida arrastando pelos 6 pontinhos; sem ordem, alfabetica no fim
        usort($tarefas, static fn (array $a, array $b): int => [$a['ordem_rotina'] ?? PHP_INT_MAX, $a['cliente_nome'] ?? '', $a['id']] <=> [$b['ordem_rotina'] ?? PHP_INT_MAX, $b['cliente_nome'] ?? '', $b['id']]);

        // tarefas do cliente ainda abertas em cada dia (pedido do dono, 23/11/2026): o cliente so conta como concluido quando todas terminam
        $abertas = [];
        $st = $this->pdo->prepare("SELECT t.cliente_id, t.prazo, COUNT(*) FROM tarefas t JOIN status s ON s.id = t.status_id
            WHERE t.cliente_id IS NOT NULL AND t.prazo BETWEEN ? AND ? AND s.tipo != 'fechado' GROUP BY t.cliente_id, t.prazo");
        $st->execute([array_key_first($semana), array_key_last($semana)]);

        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$cli, $prazo, $qtd]) {
            $abertas[$cli . '|' . $prazo] = (int) $qtd;
        }

        // fim do cliente no dia = a ultima tarefa dele concluida naquele dia (tempo de execucao, pedido do dono 24/11/2026)
        $fim = [];
        $st = $this->pdo->prepare('SELECT cliente_id, prazo, MAX(concluida_em) FROM tarefas WHERE cliente_id IS NOT NULL AND prazo BETWEEN ? AND ? AND concluida_em IS NOT NULL GROUP BY cliente_id, prazo');
        $st->execute([array_key_first($semana), array_key_last($semana)]);

        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$cli, $prazo, $quando]) {
            $fim[$cli . '|' . $prazo] = (string) $quando;
        }

        foreach ($tarefas as $t) {
            if ($t['turno'] === null || !isset($dias[$t['prazo']])) {
                continue;
            }

            $item = ['id' => $t['id'], 'titulo' => $t['titulo'], 'status_tipo' => $t['status_tipo'], 'status_nome' => $t['status_nome'], 'prioridade' => $t['prioridade'],
                'cliente_id' => $t['cliente_id'], 'cliente_nome' => $t['cliente_nome'], 'cliente_descricao' => '', 'cliente_link' => '', 'itens_total' => $t['itens_total'], 'itens_feitos' => $t['itens_feitos']];

            if ($t['cliente_id'] !== null && isset($clientes[$t['cliente_id']])) {
                $item['cliente_descricao'] = $clientes[$t['cliente_id']]['descricao'];
                $item['cliente_link'] = $clientes[$t['cliente_id']]['link'];
            }

            // analise concluida, mas ainda ha tarefas do cliente abertas no dia: o cliente continua "Em andamento"
            $item['analise_feita'] = false;

            if ($t['cliente_id'] !== null && $t['status_tipo'] === 'fechado' && ($abertas[$t['cliente_id'] . '|' . $t['prazo']] ?? 0) > 0) {
                $item['analise_feita'] = true;
                $item['status_tipo'] = 'andamento';
                $item['status_nome'] = 'Em andamento';
            }

            // tempo de execucao do cliente: do "Iniciar" ate a ultima tarefa do dia concluida (em andamento: ate agora)
            $item['tempo_seg'] = null;
            $inicio = $t['iniciada_em'] ?? null;

            if ($t['cliente_id'] !== null && $inicio) {
                $ate = $item['status_tipo'] === 'fechado' ? ($fim[$t['cliente_id'] . '|' . $t['prazo']] ?? $t['concluida_em']) : ($item['status_tipo'] === 'andamento' ? date(DATE_ATOM) : null);

                if ($ate) {
                    $item['tempo_seg'] = max(0, (int) strtotime((string) $ate) - (int) strtotime((string) $inicio));
                }
            }

            $dias[$t['prazo']]['turnos'][$t['turno']][$t['cliente_id'] === null ? 'checklist' : 'clientes'][] = $item;
            $dias[$t['prazo']]['total']++;
            $dias[$t['prazo']]['feitas'] += $item['status_tipo'] === 'fechado' ? 1 : 0;
        }

        return ['data' => self::data($data)->format('Y-m-d'), 'gestor_id' => $alvo, 'semana' => $dias];
    }

    /** Cores que o dono pode escolher para os cabecalhos dos blocos da Rotina. */
    public const CORES_CABECALHO = ['turquesa', 'azul', 'verde', 'amarelo', 'laranja', 'vermelho', 'rosa', 'roxo'];
    private const CABECALHOS_PADRAO = ['checklist' => ['nome' => 'Checklist do dia', 'cor' => 'turquesa'], 'clientes' => ['nome' => 'Clientes do dia', 'cor' => 'turquesa'], 'foco' => ['nome' => 'Em foco', 'cor' => 'turquesa']];

    /** Nome e cor dos cabecalhos "Checklist do dia" e "Clientes do dia" de quem esta usando (pedido do dono, 24/11/2026). */
    public function cabecalhos(array $actor): array
    {
        $st = $this->pdo->prepare('SELECT valor FROM meta WHERE chave = ?');
        $st->execute(['cabecalhos:' . (int) $actor['id']]);
        $salvo = json_decode((string) ($st->fetchColumn() ?: '{}'), true) ?: [];
        $res = self::CABECALHOS_PADRAO;

        foreach ($res as $bloco => $padrao) {
            $res[$bloco] = array_merge($padrao, array_intersect_key((array) ($salvo[$bloco] ?? []), $padrao));
        }

        return $res;
    }

    public function salvarCabecalho(array $actor, string $bloco, array $d): array
    {
        if (!isset(self::CABECALHOS_PADRAO[$bloco])) {
            throw new InvalidArgumentException('Cabeçalho inválido.');
        }

        $todos = $this->cabecalhos($actor);

        if (array_key_exists('nome', $d)) {
            $nome = trim((string) $d['nome']);

            if ($nome === '' || mb_strlen($nome) > 40) {
                throw new InvalidArgumentException('Escreva o nome do cabeçalho (até 40 caracteres).');
            }

            $todos[$bloco]['nome'] = $nome;
        }

        if (array_key_exists('cor', $d)) {
            if (!in_array($d['cor'], self::CORES_CABECALHO, true)) {
                throw new InvalidArgumentException('Escolha uma das cores da lista.');
            }

            $todos[$bloco]['cor'] = $d['cor'];
        }

        $this->pdo->prepare('INSERT OR REPLACE INTO meta (chave, valor) VALUES (?, ?)')->execute(['cabecalhos:' . (int) $actor['id'], json_encode($todos, JSON_UNESCAPED_UNICODE)]);

        return $todos;
    }

    /** Nova ordem dos clientes de um periodo (arrastar pelos 6 pontinhos); quem veio de outro periodo passa para este. */
    public function ordenar(array $actor, string $turno, array $ids): void
    {
        if (!in_array($turno, self::TURNOS, true)) {
            throw new InvalidArgumentException('Escolha um turno válido.');
        }

        foreach (array_values($ids) as $i => $id) {
            $t = $this->tarefas->obter($actor, (int) $id);

            if ($t['turno'] !== $turno) {
                $this->tarefas->atualizar($actor, (int) $id, ['turno' => $turno]);
            }

            $this->pdo->prepare('UPDATE tarefas SET ordem_rotina = ? WHERE id = ?')->execute([$i + 1, (int) $id]);
        }
    }

    public function iniciar(array $actor, int $tarefaId): void
    {
        $this->tarefas->moverPorTipo($actor, $tarefaId, 'andamento');
    }

    public function concluir(array $actor, int $tarefaId, string $nota = ''): void
    {
        $this->tarefas->moverPorTipo($actor, $tarefaId, 'fechado');

        if (trim($nota) !== '') {
            $this->tarefas->comentar($actor, $tarefaId, $nota);
        }
    }

    public function reabrir(array $actor, int $tarefaId): void
    {
        $this->tarefas->moverPorTipo($actor, $tarefaId, 'aberto');
    }

    /** Gera agora a rotina de hoje (as mesmas automacoes agendadas; nao duplica o que ja existe). */
    public function gerarDoDia(DateTimeImmutable $agora): array
    {
        $criadas = 0;

        foreach ($this->automacoes->listar() as $a) {
            if ($a['ativa'] && $a['gatilho'] === 'agenda') {
                $criadas += $this->automacoes->executarAgora(Nucleo::sistema(), $a['id'], $agora)['tarefas_criadas'];
            }
        }

        return ['tarefas_criadas' => $criadas];
    }

    private static function data(string $data): DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('Informe uma data válida (AAAA-MM-DD).');
        }

        return new DateTimeImmutable($data);
    }
}
