<?php

declare(strict_types=1);

putenv('ROTINA_SECRET=chave-so-para-testes');
putenv('ROTINA_SENHA_MIN=8'); // os testes exercitam a regra de producao (minimo 8); o padrao local e 1
require_once dirname(__DIR__) . '/src/bootstrap.php';

$GLOBALS['t_total'] = 0;
$GLOBALS['t_falhas'] = [];

function t(string $nome, callable $fn): void
{
    $GLOBALS['t_total']++;

    try {
        $fn();
        echo '  ok  ' . $nome . PHP_EOL;
    } catch (Throwable $e) {
        $GLOBALS['t_falhas'][] = $nome . ': ' . $e->getMessage();
        echo '  FAIL ' . $nome . ' -> ' . $e->getMessage() . PHP_EOL;
    }
}

function eq(mixed $esperado, mixed $atual, string $msg = ''): void
{
    if ($esperado !== $atual) {
        throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'esperado ' . json_encode($esperado, JSON_UNESCAPED_UNICODE) . ' mas veio ' . json_encode($atual, JSON_UNESCAPED_UNICODE));
    }
}

function ok(bool $cond, string $msg = 'condicao falsa'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function lanca(callable $fn, string $trechoDaMensagem = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($trechoDaMensagem !== '' && !str_contains($e->getMessage(), $trechoDaMensagem)) {
            throw new RuntimeException('excecao com outra mensagem: ' . $e->getMessage());
        }

        return;
    }

    throw new RuntimeException('era esperada uma excecao' . ($trechoDaMensagem !== '' ? ' (' . $trechoDaMensagem . ')' : ''));
}

/** Banco novo em memoria, ja migrado. */
function banco_teste(): PDO
{
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);

    return $pdo;
}

const SEGUNDA_6H = '2026-11-09 06:00:00';

/** Servicos + admin e dois gestores. */
function nucleo_teste(): array
{
    $pdo = banco_teste();
    $n = Nucleo::montar($pdo);
    $admin = $n->auth->buscar($n->auth->criar('Admin', 'admin@exemplo.com', 'senhaforte1', 'admin'));
    $ana = $n->auth->buscar($n->auth->criar('Ana', 'ana@exemplo.com', 'senhaforte1', 'gestor'));
    $beto = $n->auth->buscar($n->auth->criar('Beto', 'beto@exemplo.com', 'senhaforte1', 'gestor'));

    return [$n, $admin, $ana, $beto];
}

/** Idem, com clientes: Ana (BILL seg/qui manha, LAVANDERIA seg tarde, PAUSADO, NOVO) e Beto (DB ter manha). */
function cenario_auto(): array
{
    [$n, $admin, $ana, $beto] = nucleo_teste();
    $ids = [
        'bill' => $n->clientes->criar($ana, ['nome' => 'BILL BORRACHA', 'dias' => ['segunda', 'quinta'], 'turno' => 'manha']),
        'lav' => $n->clientes->criar($ana, ['nome' => 'LAVANDERIA', 'dias' => ['segunda'], 'turno' => 'tarde']),
        'db' => $n->clientes->criar($beto, ['nome' => 'DB CAMARÕES', 'dias' => ['terca'], 'turno' => 'manha']),
        'pausado' => $n->clientes->criar($ana, ['nome' => 'PAUSADO', 'dias' => ['segunda'], 'status' => 'pausado']),
        'novo' => $n->clientes->criar($ana, ['nome' => 'NOVO', 'dias' => ['segunda'], 'status' => 'novo']),
    ];

    return [$n, $admin, $ana, $beto, $ids];
}

function tarefas_de_origem(Nucleo $n, string $filtro = ''): array
{
    $sql = "SELECT t.titulo, t.turno, t.prazo, t.responsavel_id, t.lista_id, t.origem, t.cliente_id FROM tarefas t WHERE t.origem = 'automacao'" . ($filtro !== '' ? ' AND ' . $filtro : '') . ' ORDER BY t.id';

    return $n->pdo->query($sql)->fetchAll();
}

function t_resumo(): int
{
    $falhas = $GLOBALS['t_falhas'];
    echo PHP_EOL . ($GLOBALS['t_total'] - count($falhas)) . ' de ' . $GLOBALS['t_total'] . ' testes passaram' . PHP_EOL;

    foreach ($falhas as $f) {
        echo ' - ' . $f . PHP_EOL;
    }

    return $falhas === [] ? 0 : 1;
}
