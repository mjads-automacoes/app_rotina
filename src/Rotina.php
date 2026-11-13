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
        usort($tarefas, static fn (array $a, array $b): int => [$a['cliente_nome'] ?? '', $a['id']] <=> [$b['cliente_nome'] ?? '', $b['id']]);

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

            $dias[$t['prazo']]['turnos'][$t['turno']][$t['cliente_id'] === null ? 'checklist' : 'clientes'][] = $item;
            $dias[$t['prazo']]['total']++;
            $dias[$t['prazo']]['feitas'] += $t['status_tipo'] === 'fechado' ? 1 : 0;
        }

        return ['data' => self::data($data)->format('Y-m-d'), 'gestor_id' => $alvo, 'semana' => $dias];
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

    /** Tarefas concluidas (mais recentes primeiro), com o ultimo comentario como nota. */
    public function historico(array $actor, array $f): array
    {
        $filtros = ['concluidas' => true];

        foreach (['cliente_id', 'responsavel_id', 'de', 'ate'] as $k) {
            if (!empty($f[$k])) {
                $filtros[$k] = $f[$k];
            }
        }

        $tarefas = $this->tarefas->listar($actor, $filtros, 500);
        usort($tarefas, static fn (array $a, array $b): int => strcmp((string) $b['concluida_em'], (string) $a['concluida_em']));
        $nota = $this->pdo->prepare("SELECT texto FROM tarefa_atividades WHERE tarefa_id = ? AND tipo = 'comentario' ORDER BY id DESC LIMIT 1");

        foreach ($tarefas as &$t) {
            $nota->execute([$t['id']]);
            $t['nota'] = (string) ($nota->fetchColumn() ?: '');
        }

        return $tarefas;
    }

    private static function data(string $data): DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('Informe uma data válida (AAAA-MM-DD).');
        }

        return new DateTimeImmutable($data);
    }
}
