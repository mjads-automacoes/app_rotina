<?php

declare(strict_types=1);

final class Listas
{
    public const TIPOS = ['aberto', 'andamento', 'fechado'];

    public function __construct(private PDO $pdo)
    {
    }

    /** Todas as listas, cada uma com seus status em ordem. */
    public function listar(): array
    {
        $listas = $this->pdo->query('SELECT id, nome, cor FROM listas ORDER BY id')->fetchAll();
        $status = $this->pdo->query('SELECT id, lista_id, nome, cor, tipo, ordem FROM status ORDER BY lista_id, ordem')->fetchAll();

        foreach ($listas as &$l) {
            $l['id'] = (int) $l['id'];
            $l['status'] = [];

            foreach ($status as $s) {
                if ((int) $s['lista_id'] === $l['id']) {
                    $l['status'][] = ['id' => (int) $s['id'], 'nome' => $s['nome'], 'cor' => $s['cor'], 'tipo' => $s['tipo'], 'ordem' => (int) $s['ordem']];
                }
            }
        }

        return $listas;
    }

    public function obter(int $id): ?array
    {
        foreach ($this->listar() as $l) {
            if ($l['id'] === $id) {
                return $l;
            }
        }

        return null;
    }

    /** @param array<int, array{nome:string,tipo:string,cor?:string}> $status */
    public function criar(array $actor, string $nome, array $status): int
    {
        if ($actor['papel'] !== 'admin') {
            throw new AcessoNegado('Só o administrador gerencia as listas.');
        }

        $nome = trim($nome);

        if ($nome === '' || mb_strlen($nome) > 80) {
            throw new InvalidArgumentException('Informe o nome da lista (até 80 caracteres).');
        }

        $nomes = [];

        foreach ($status as $s) {
            if (trim((string) ($s['nome'] ?? '')) === '' || !in_array($s['tipo'] ?? '', self::TIPOS, true)) {
                throw new InvalidArgumentException('Cada status precisa de nome e tipo (aberto, andamento ou fechado).');
            }

            $nomes[] = $s['tipo'];
        }

        if (!in_array('fechado', $nomes, true) || !in_array('aberto', $nomes, true)) {
            throw new InvalidArgumentException('A lista precisa ter ao menos um status do tipo aberto e um do tipo fechado.');
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('INSERT INTO listas (nome, cor, criado_em) VALUES (?, ?, ?)')->execute([$nome, '#7c5cff', date(DATE_ATOM)]);
            $id = (int) $this->pdo->lastInsertId();
            $ins = $this->pdo->prepare('INSERT INTO status (lista_id, nome, cor, tipo, ordem) VALUES (?, ?, ?, ?, ?)');
            $cores = ['aberto' => '#94a3b8', 'andamento' => '#3b82f6', 'fechado' => '#22c55e'];

            foreach (array_values($status) as $i => $s) {
                $cor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($s['cor'] ?? '')) ? $s['cor'] : $cores[$s['tipo']];
                $ins->execute([$id, trim((string) $s['nome']), $cor, $s['tipo'], $i + 1]);
            }

            $this->pdo->commit();

            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
