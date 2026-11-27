<?php

declare(strict_types=1);

/**
 * Aba Agentes (27/11/2026): pedidos da equipe aos agentes do escritorio (Gestor de Trafego, Criativos, Dev Sites,
 * Social Media). Os formularios vem de src/agentes-formularios.json (especificacao do dono). Cada pedido tem status
 * e uma linha do tempo (pedido_eventos). Quem pede tambem aprova (decisao do dono). Os agentes (OpenClaw, via MCP)
 * mudam status, perguntam e entregam pelos metodos marcados "agente".
 */
final class Pedidos
{
    public const STATUS = [
        'a_fazer' => 'A fazer', 'fazendo' => 'Fazendo', 'falta_informacao' => 'Falta informação', 'para_aprovacao' => 'Para aprovação',
        'ajuste' => 'Ajuste', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado',
    ];
    public const PRIORIDADES = ['urgente' => 'Urgente', 'alta' => 'Alta', 'normal' => 'Normal', 'baixa' => 'Baixa'];
    private const FINAIS = ['concluido', 'cancelado'];
    private const COMUNS = ['agente', 'tipo', 'cliente_id', 'prioridade', 'prioridade_motivo', 'prazo', 'solicitante_id', 'aprovador_id'];

    private ?array $esquema = null;

    public function __construct(private PDO $pdo, private Clientes $clientes)
    {
    }

    /** Especificacao dos formularios, com os "$ref" (o formulario "Outro" compartilhado) ja resolvidos. */
    public function esquema(): array
    {
        if ($this->esquema !== null) {
            return $this->esquema;
        }

        $d = json_decode((string) file_get_contents(__DIR__ . '/agentes-formularios.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($d['agents'] as $a => $agente) {
            foreach ($agente['forms'] as $t => $form) {
                if (isset($form['$ref'])) {
                    $partes = explode('/', ltrim(substr((string) $form['$ref'], 1), '/'));
                    $alvo = $d;

                    foreach ($partes as $p) {
                        $alvo = $alvo[$p];
                    }

                    $d['agents'][$a]['forms'][$t] = $alvo;
                }
            }
        }

        return $this->esquema = $d;
    }

    public static function codigo(int $id): string
    {
        return 'PED-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    // ---------- equipe ----------

    /** Cria o pedido (status "A fazer"). Quem pede e o solicitante e tambem o aprovador. */
    public function criar(array $actor, array $d): int
    {
        $esq = $this->esquema();
        $agente = (string) ($d['agente'] ?? '');
        $tipo = (string) ($d['tipo'] ?? '');

        if (!isset($esq['agents'][$agente]['forms'][$tipo])) {
            throw new InvalidArgumentException('Escolha o agente e o tipo de pedido.');
        }

        $cliente = $this->clientes->buscar($actor, (int) ($d['cliente_id'] ?? 0)); // so um cliente, e precisa existir
        $prioridade = (string) ($d['prioridade'] ?? 'normal');

        if (!isset(self::PRIORIDADES[$prioridade])) {
            throw new InvalidArgumentException('Escolha a prioridade.');
        }

        if ($prioridade === 'urgente' && trim((string) ($d['prioridade_motivo'] ?? '')) === '') {
            throw new InvalidArgumentException('Pedido urgente precisa de um motivo curto.');
        }

        $prazo = (string) ($d['prazo'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prazo) || $prazo < date('Y-m-d')) {
            throw new InvalidArgumentException('Informe um prazo de hoje em diante.');
        }

        $campos = (array) ($d['campos'] ?? []);
        $campos = $this->validarCampos(array_merge($esq['common_fields'], $esq['agents'][$agente]['forms'][$tipo]['fields']), $campos);
        $campos['prioridade_motivo'] = trim((string) ($d['prioridade_motivo'] ?? ''));
        $agora = date(DATE_ATOM);
        $this->pdo->prepare('INSERT INTO pedidos (agente, tipo, cliente_id, cliente_nome, prioridade, prazo, solicitante_id, aprovador_id, status, campos, criado_em, atualizado_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$agente, $tipo, $cliente['id'], $cliente['nome'], $prioridade, $prazo, $actor['id'], $actor['id'], 'a_fazer', json_encode($campos, JSON_UNESCAPED_UNICODE), $agora, $agora]);
        $id = (int) $this->pdo->lastInsertId();
        $this->evento($id, $actor, 'criado', 'Pedido enviado.');

        return $id;
    }

    /** Lista para a aba: admin ve tudo; o gestor ve o que pediu e os pedidos dos clientes dele. */
    public function listar(array $actor, array $f = []): array
    {
        $onde = ['1 = 1'];
        $args = [];

        if ($actor['papel'] !== 'admin') {
            $onde[] = '(p.solicitante_id = ? OR c.gestor_id = ?)';
            $args[] = $actor['id'];
            $args[] = $actor['id'];
        }

        foreach (['agente' => 'p.agente', 'status' => 'p.status', 'cliente_id' => 'p.cliente_id', 'solicitante_id' => 'p.solicitante_id'] as $chave => $coluna) {
            if (($f[$chave] ?? '') !== '') {
                $onde[] = $coluna . ' = ?';
                $args[] = $f[$chave];
            }
        }

        if (!empty($f['abertos'])) {
            $onde[] = "p.status NOT IN ('concluido', 'cancelado')";
        }

        $st = $this->pdo->prepare('SELECT p.*, u.nome AS solicitante_nome FROM pedidos p LEFT JOIN clientes c ON c.id = p.cliente_id LEFT JOIN usuarios u ON u.id = p.solicitante_id
            WHERE ' . implode(' AND ', $onde) . " ORDER BY CASE p.status WHEN 'falta_informacao' THEN 0 WHEN 'para_aprovacao' THEN 1 WHEN 'ajuste' THEN 2 WHEN 'fazendo' THEN 3 WHEN 'a_fazer' THEN 4 ELSE 5 END, p.prazo, p.id DESC LIMIT 500");
        $st->execute($args);

        return array_map([$this, 'formatar'], $st->fetchAll());
    }

    public function obter(array $actor, int $id): array
    {
        $p = $this->buscar($id);

        if ($actor['papel'] !== 'admin' && (int) $p['solicitante_id'] !== (int) $actor['id']) {
            $dono = $p['cliente_id'] !== null ? $this->pdo->query('SELECT gestor_id FROM clientes WHERE id = ' . (int) $p['cliente_id'])->fetchColumn() : false;

            if ((int) $dono !== (int) $actor['id']) {
                throw new RuntimeException('Pedido não encontrado.');
            }
        }

        $st = $this->pdo->prepare('SELECT * FROM pedido_eventos WHERE pedido_id = ? ORDER BY id');
        $st->execute([$id]);
        $p['eventos'] = array_map(static function (array $e): array {
            $e['dados'] = json_decode((string) $e['dados'], true) ?: [];

            return $e;
        }, $st->fetchAll());

        return $p;
    }

    /** Resposta a uma pergunta do agente: o pedido volta para "Fazendo". */
    public function responder(array $actor, int $id, string $texto): void
    {
        $p = $this->obter($actor, $id);
        $this->exigirTexto($texto, 'Escreva a resposta.');

        if ($p['status'] !== 'falta_informacao') {
            throw new InvalidArgumentException('Este pedido não está esperando resposta.');
        }

        $this->mudar($id, 'fazendo');
        $this->evento($id, $actor, 'resposta', trim($texto));
    }

    /** Aprovar, pedir ajuste ou cancelar. So quem aprova (o proprio solicitante) ve e usa. */
    public function decidir(array $actor, int $id, string $acao, string $texto = ''): void
    {
        $p = $this->obter($actor, $id);
        $aprovador = (int) $p['aprovador_id'] === (int) $actor['id'] || $actor['papel'] === 'admin';

        if (in_array($p['status'], self::FINAIS, true)) {
            throw new InvalidArgumentException('Este pedido já foi encerrado.');
        }

        if (!$aprovador && $acao !== 'cancelar') {
            throw new AcessoNegado('Só quem aprova este pedido pode fazer isso.');
        }

        if ($acao === 'aprovar') {
            if ($p['status'] !== 'para_aprovacao') {
                throw new InvalidArgumentException('Ainda não há entrega para aprovar.');
            }

            $this->mudar($id, 'concluido');
            $this->evento($id, $actor, 'aprovado', trim($texto) !== '' ? trim($texto) : 'Aprovado.');

            return;
        }

        if ($acao === 'ajuste') {
            if ($p['status'] !== 'para_aprovacao') {
                throw new InvalidArgumentException('Só dá para pedir ajuste depois da entrega.');
            }

            $this->exigirTexto($texto, 'Explique o ajuste que precisa.');
            $this->mudar($id, 'ajuste');
            $this->evento($id, $actor, 'ajuste', trim($texto));

            return;
        }

        if ($acao === 'cancelar') {
            if (!$aprovador && (int) $p['solicitante_id'] !== (int) $actor['id']) {
                throw new AcessoNegado('Só quem pediu ou quem aprova pode cancelar.');
            }

            $this->exigirTexto($texto, 'Escreva o motivo do cancelamento.');
            $this->mudar($id, 'cancelado');
            $this->evento($id, $actor, 'cancelado', trim($texto));

            return;
        }

        throw new InvalidArgumentException('Ação inválida.');
    }

    public function comentar(array $actor, int $id, string $texto): void
    {
        $this->obter($actor, $id);
        $this->exigirTexto($texto, 'Escreva o comentário.');
        $this->evento($id, $actor, 'comentario', trim($texto));
    }

    // ---------- agente (OpenClaw, pelo MCP) ----------

    /** O agente comecou (Fazendo) ou devolve o pedido para a fila. */
    public function agenteStatus(int $id, string $status, string $agente, string $texto = ''): void
    {
        $p = $this->buscar($id);

        if (!in_array($status, ['fazendo'], true) || in_array($p['status'], self::FINAIS, true)) {
            throw new InvalidArgumentException('O agente só marca "fazendo" em pedido aberto.');
        }

        $this->mudar($id, $status);
        $this->evento($id, ['nome' => $agente], 'status', trim($texto) !== '' ? trim($texto) : 'Agente começou.');
    }

    /** Falta informacao: a pergunta aparece no pedido para o solicitante responder. */
    public function agentePerguntar(int $id, string $pergunta, string $agente): void
    {
        $p = $this->buscar($id);
        $this->exigirTexto($pergunta, 'Escreva a pergunta.');

        if (in_array($p['status'], self::FINAIS, true)) {
            throw new InvalidArgumentException('Pedido encerrado.');
        }

        $this->mudar($id, 'falta_informacao');
        $this->evento($id, ['nome' => $agente], 'pergunta', trim($pergunta));
    }

    /** Entrega (v1, v2...): resumo, arquivos, links, textos e o que conferir. Vai para "Para aprovacao". */
    public function agenteEntregar(int $id, array $entrega, string $agente): int
    {
        $p = $this->buscar($id);

        if (in_array($p['status'], self::FINAIS, true)) {
            throw new InvalidArgumentException('Pedido encerrado.');
        }

        $resumo = trim((string) ($entrega['resumo'] ?? ''));
        $this->exigirTexto($resumo, 'A entrega precisa de um resumo.');
        $limpa = ['resumo' => mb_substr($resumo, 0, 2000)];

        foreach (['arquivos', 'links'] as $k) {
            $limpa[$k] = array_values(array_filter(array_map(static function ($x): ?array {
                $url = trim((string) (is_array($x) ? ($x['url'] ?? '') : $x));

                return preg_match('#^https?://#i', $url) ? ['nome' => mb_substr(trim((string) (is_array($x) ? ($x['nome'] ?? $url) : $url)), 0, 200), 'url' => $url] : null;
            }, (array) ($entrega[$k] ?? []))));
        }

        foreach (['textos', 'checklist'] as $k) {
            $limpa[$k] = array_values(array_filter(array_map(static fn ($x): string => mb_substr(trim(is_array($x) ? (string) (($x['titulo'] ?? '') . ': ' . ($x['texto'] ?? '')) : (string) $x), 0, 5000), (array) ($entrega[$k] ?? [])), 'strlen'));
        }

        $versao = (int) $p['versao'] + 1;
        $limpa['versao'] = $versao;
        $this->pdo->prepare('UPDATE pedidos SET versao = ? WHERE id = ?')->execute([$versao, $id]);
        $this->mudar($id, 'para_aprovacao');
        $this->evento($id, ['nome' => $agente], 'entrega', $resumo, $limpa);

        return $versao;
    }

    // ---------- apoio ----------

    private function validarCampos(array $campos, array $valores): array
    {
        $limpos = [];

        foreach ($campos as $c) {
            $id = (string) $c['id'];

            if (in_array($id, self::COMUNS, true)) {
                continue;
            }

            $v = $valores[$id] ?? null;
            $obrigatorio = !empty($c['required']) && empty($c['soft_required']);

            if (isset($c['required_if'])) {
                $obrigatorio = in_array($valores[$c['required_if']['field']] ?? null, (array) $c['required_if']['in'], true);
            }

            if ($obrigatorio && self::vazio($v)) {
                throw new InvalidArgumentException('Preencha o campo "' . $c['label'] . '".');
            }

            if (is_array($v) && ($c['type'] ?? '') === 'offer' && empty($v['sem_oferta']) && !self::vazio($v['texto'] ?? '')) {
                $validade = (string) ($v['validade'] ?? '');

                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validade) || $validade < date('Y-m-d')) {
                    throw new InvalidArgumentException('A oferta em "' . $c['label'] . '" precisa de uma validade de hoje em diante.');
                }
            }

            if (!self::vazio($v)) {
                $limpos[$id] = self::podar($v);
            }
        }

        return $limpos;
    }

    private static function vazio(mixed $v): bool
    {
        if (is_array($v)) {
            if (!empty($v['nenhum']) || !empty($v['sem_oferta'])) {
                return false; // "nao se aplica", "sem oferta": resposta intencional
            }

            foreach ($v as $x) {
                if (!self::vazio($x)) {
                    return false;
                }
            }

            return true;
        }

        if ($v === null) {
            return true;
        }

        return !is_bool($v) && !is_int($v) && !is_float($v) && trim((string) $v) === '';
    }

    /** Limita o tamanho do que vem do formulario (textos e listas). */
    private static function podar(mixed $v, int $nivel = 0): mixed
    {
        if (is_array($v)) {
            return $nivel > 4 ? [] : array_map(static fn ($x) => self::podar($x, $nivel + 1), array_slice($v, 0, 50, true));
        }

        return is_string($v) ? mb_substr($v, 0, 5000) : (is_bool($v) || is_int($v) || is_float($v) ? $v : null);
    }

    private function buscar(int $id): array
    {
        $st = $this->pdo->prepare('SELECT p.*, u.nome AS solicitante_nome FROM pedidos p LEFT JOIN usuarios u ON u.id = p.solicitante_id WHERE p.id = ?');
        $st->execute([$id]);
        $p = $st->fetch();

        if ($p === false) {
            throw new RuntimeException('Pedido não encontrado.');
        }

        return $this->formatar($p);
    }

    private function formatar(array $p): array
    {
        $p['id'] = (int) $p['id'];
        $p['codigo'] = self::codigo($p['id']);
        $p['campos'] = json_decode((string) $p['campos'], true) ?: [];
        $p['status_nome'] = self::STATUS[$p['status']] ?? $p['status'];
        $esq = $this->esquema();
        $p['agente_nome'] = $esq['agents'][$p['agente']]['label'] ?? $p['agente'];
        $p['tipo_nome'] = $esq['agents'][$p['agente']]['forms'][$p['tipo']]['label'] ?? $p['tipo'];

        return $p;
    }

    private function mudar(int $id, string $status): void
    {
        $this->pdo->prepare('UPDATE pedidos SET status = ?, atualizado_em = ? WHERE id = ?')->execute([$status, date(DATE_ATOM), $id]);
    }

    private function evento(int $id, array $autor, string $tipo, string $texto, array $dados = []): void
    {
        $this->pdo->prepare('INSERT INTO pedido_eventos (pedido_id, autor, usuario_id, tipo, texto, dados, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, (string) ($autor['nome'] ?? ''), $autor['id'] ?? null, $tipo, mb_substr($texto, 0, 5000), json_encode($dados, JSON_UNESCAPED_UNICODE), date(DATE_ATOM)]);
    }

    private function exigirTexto(string $texto, string $mensagem): void
    {
        if (trim($texto) === '' || mb_strlen($texto) > 5000) {
            throw new InvalidArgumentException($mensagem);
        }
    }
}
