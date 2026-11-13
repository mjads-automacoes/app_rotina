<?php

declare(strict_types=1);

final class Clientes
{
    public const PLATAFORMAS = ['google', 'meta', 'tiktok', 'linkedin', 'outros'];

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
        $this->pdo->prepare('INSERT INTO clientes (gestor_id, nome, empresa, descricao, link, dias, turno, status, contato_nome, contato_telefone, contato_email, plataformas, orcamento, observacoes, clickup_id, clickup_dados, criado_em, atualizado_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$gestorId, $v['nome'], $v['empresa'], $v['descricao'], $v['link'], implode(',', $v['dias']), $v['turno'], $v['status'], $v['contato_nome'], $v['contato_telefone'],
                $v['contato_email'], implode(',', $v['plataformas']), $v['orcamento'], $v['observacoes'], $dados['clickup_id'] ?? null, $dados['clickup_dados'] ?? null, $agora, $agora]);

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
            plataformas = ?, orcamento = ?, observacoes = ?, clickup_dados = ?, atualizado_em = ? WHERE id = ?')
            ->execute([$gestorId, $v['nome'], $v['empresa'], $v['descricao'], $v['link'], implode(',', $v['dias']), $v['turno'], $v['status'], $v['contato_nome'], $v['contato_telefone'], $v['contato_email'],
                implode(',', $v['plataformas']), $v['orcamento'], $v['observacoes'], array_key_exists('clickup_dados', $dados) ? $dados['clickup_dados'] : $atual['clickup_dados'], date(DATE_ATOM), $id]);
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
        $this->pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$id]);
    }

    // ---------- notas ----------

    /** Mais recente primeiro. */
    public function notas(array $actor, int $id): array
    {
        $this->buscar($actor, $id);
        $st = $this->pdo->prepare('SELECT id, autor, texto, origem, criado_em FROM cliente_notas WHERE cliente_id = ? ORDER BY id DESC');
        $st->execute([$id]);

        return $st->fetchAll();
    }

    public function notaAdicionar(array $actor, int $id, string $texto): void
    {
        $this->buscar($actor, $id);
        $texto = trim($texto);

        if ($texto === '' || mb_strlen($texto) > 2000) {
            throw new InvalidArgumentException('Escreva a nota (até 2000 caracteres).');
        }

        $this->pdo->prepare("INSERT INTO cliente_notas (cliente_id, autor, texto, origem, criado_em) VALUES (?, ?, ?, 'manual', ?)")->execute([$id, (string) ($actor['nome'] ?? ''), $texto, date(DATE_ATOM)]);
    }

    /** Nota vinda de fora (ClickUp). Devolve true se era nova. */
    public function notaImportada(int $clienteId, string $autor, string $texto, string $origem, string $origemId, string $criadoEm): bool
    {
        $st = $this->pdo->prepare('INSERT OR IGNORE INTO cliente_notas (cliente_id, autor, texto, origem, origem_id, criado_em) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$clienteId, mb_substr($autor, 0, 120), mb_substr($texto, 0, 2000), $origem, $origemId, $criadoEm]);

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

        if (!in_array($status, ['ativo', 'novo', 'pausado'], true)) {
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

        $curtos = [];

        foreach (['empresa' => 120, 'descricao' => 500, 'contato_nome' => 120, 'contato_telefone' => 30, 'orcamento' => 60] as $campo => $max) {
            $valor = trim((string) ($d[$campo] ?? ''));

            if (mb_strlen($valor) > $max) {
                throw new InvalidArgumentException('O campo ' . $campo . ' é muito longo (máximo ' . $max . ' caracteres).');
            }

            $curtos[$campo] = $valor;
        }

        $observacoes = trim((string) ($d['observacoes'] ?? ''));

        if (mb_strlen($observacoes) > 2000 || mb_strlen($link) > 500) {
            throw new InvalidArgumentException('Texto muito longo nas observações ou no link (observações até 2000 caracteres).');
        }

        return ['nome' => $nome, 'link' => $link, 'dias' => $dias, 'turno' => $turno, 'status' => $status, 'contato_email' => $email, 'plataformas' => $plataformas, 'observacoes' => $observacoes] + $curtos;
    }

    private function formatar(array $c): array
    {
        $c['id'] = (int) $c['id'];
        $c['gestor_id'] = (int) $c['gestor_id'];
        $c['dias'] = $c['dias'] === '' ? [] : explode(',', (string) $c['dias']);
        $c['plataformas'] = $c['plataformas'] === '' ? [] : explode(',', (string) $c['plataformas']);

        return $c;
    }
}
