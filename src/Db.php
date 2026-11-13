<?php

declare(strict_types=1);

final class Db
{
    public const VERSAO = 2;

    public static function open(string $caminho): PDO
    {
        $pdo = new PDO('sqlite:' . $caminho, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        if ($caminho !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return $pdo;
    }

    /** Abre (e migra) o banco do arquivo configurado. */
    public static function padrao(): PDO
    {
        $dir = (string) rotina_config('data_dir');

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = self::open($dir . '/rotina.sqlite');
        self::migrate($pdo);

        return $pdo;
    }

    public static function migrate(PDO $pdo): void
    {
        $atual = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

        if ($atual >= self::VERSAO) {
            return;
        }

        $pdo->beginTransaction();

        try {
            if ($atual < 1) {
                self::v1($pdo);
            }

            if ($atual < 2) {
                self::v2($pdo);
            }

            $pdo->exec('PRAGMA user_version = ' . self::VERSAO);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function v1(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            senha_hash TEXT NOT NULL,
            papel TEXT NOT NULL CHECK (papel IN ('admin','gestor')),
            ativo INTEGER NOT NULL DEFAULT 1,
            criado_em TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE clientes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            gestor_id INTEGER NOT NULL REFERENCES usuarios(id),
            nome TEXT NOT NULL,
            descricao TEXT NOT NULL DEFAULT '',
            link TEXT NOT NULL DEFAULT '',
            dias TEXT NOT NULL DEFAULT '',
            turno TEXT NOT NULL DEFAULT 'manha' CHECK (turno IN ('manha','tarde','fechamento')),
            status TEXT NOT NULL DEFAULT 'ativo' CHECK (status IN ('ativo','novo','pausado')),
            criado_em TEXT NOT NULL,
            atualizado_em TEXT NOT NULL
        )");
        $pdo->exec('CREATE INDEX idx_clientes_gestor ON clientes(gestor_id)');
        $pdo->exec("CREATE TABLE andamento (
            data TEXT NOT NULL,
            cliente_id INTEGER NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
            status TEXT NOT NULL CHECK (status IN ('pendente','andamento','concluido')),
            usuario_id INTEGER NOT NULL,
            atualizado_em TEXT NOT NULL,
            PRIMARY KEY (data, cliente_id)
        )");
        $pdo->exec("CREATE TABLE checklist_modelo (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            turno TEXT NOT NULL CHECK (turno IN ('manha','tarde','fechamento')),
            chave TEXT NOT NULL,
            texto TEXT NOT NULL,
            ordem INTEGER NOT NULL,
            automatico INTEGER NOT NULL DEFAULT 0,
            UNIQUE (turno, chave)
        )");
        $pdo->exec("CREATE TABLE checklist_marcas (
            data TEXT NOT NULL,
            gestor_id INTEGER NOT NULL REFERENCES usuarios(id),
            turno TEXT NOT NULL,
            chave TEXT NOT NULL,
            PRIMARY KEY (data, gestor_id, turno, chave)
        )");
        $pdo->exec("CREATE TABLE registros (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            data TEXT NOT NULL,
            cliente_id INTEGER NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
            usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
            tipo TEXT NOT NULL DEFAULT 'analise',
            nota TEXT NOT NULL DEFAULT '',
            criado_em TEXT NOT NULL
        )");
        $pdo->exec('CREATE INDEX idx_registros_data ON registros(data)');
        $pdo->exec("CREATE TABLE tentativas_login (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            ip TEXT NOT NULL,
            criado_em INTEGER NOT NULL
        )");
        $pdo->exec('CREATE INDEX idx_tentativas ON tentativas_login(email, ip, criado_em)');

        // Checklist padrao (pode virar editavel depois). "automatico": o item se marca sozinho quando todos os clientes do turno estao concluidos.
        $itens = [
            ['manha', 'grupos', 'Atenção nos grupos', 0],
            ['manha', 'contas', 'Verificar contas e saldo', 0],
            ['manha', 'prioridades', 'Organizar prioridades do dia', 0],
            ['manha', 'analisar', 'Analisar {n} clientes', 1],
            ['manha', 'otimizacoes', 'Executar otimizações, testes e publicações', 0],
            ['tarde', 'grupos', 'Atenção nos grupos', 0],
            ['tarde', 'analisar', 'Analisar {n} clientes', 1],
            ['tarde', 'otimizacoes', 'Executar otimizações, testes e publicações', 0],
            ['tarde', 'retornos', 'Responder clientes pendentes', 0],
            ['fechamento', 'analisar', 'Analisar {n} clientes', 1],
            ['fechamento', 'resultados', 'Registrar resultados do dia', 0],
            ['fechamento', 'pendencias', 'Anotar pendências para amanhã', 0],
        ];
        $ins = $pdo->prepare('INSERT INTO checklist_modelo (turno, chave, texto, ordem, automatico) VALUES (?, ?, ?, ?, ?)');

        foreach ($itens as $i => [$turno, $chave, $texto, $auto]) {
            $ins->execute([$turno, $chave, $texto, $i + 1, $auto]);
        }
    }

    /** v2: tarefas estilo ClickUp (listas, status, etiquetas, checklist, comentarios), automacoes internas e ficha completa do cliente. */
    private static function v2(PDO $pdo): void
    {
        foreach (['andamento', 'checklist_marcas', 'checklist_modelo', 'registros'] as $antiga) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $antiga);
        }

        foreach ([
            "empresa TEXT NOT NULL DEFAULT ''", "contato_nome TEXT NOT NULL DEFAULT ''", "contato_telefone TEXT NOT NULL DEFAULT ''",
            "contato_email TEXT NOT NULL DEFAULT ''", "plataformas TEXT NOT NULL DEFAULT ''", "orcamento TEXT NOT NULL DEFAULT ''",
            "observacoes TEXT NOT NULL DEFAULT ''", 'clickup_id TEXT', 'clickup_dados TEXT',
        ] as $coluna) {
            $pdo->exec('ALTER TABLE clientes ADD COLUMN ' . $coluna);
        }

        $pdo->exec('CREATE UNIQUE INDEX idx_clientes_clickup ON clientes(gestor_id, clickup_id) WHERE clickup_id IS NOT NULL');
        $pdo->exec("CREATE TABLE cliente_notas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cliente_id INTEGER NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
            autor TEXT NOT NULL DEFAULT '',
            texto TEXT NOT NULL,
            origem TEXT NOT NULL DEFAULT 'manual',
            origem_id TEXT,
            criado_em TEXT NOT NULL
        )");
        $pdo->exec('CREATE UNIQUE INDEX idx_notas_origem ON cliente_notas(cliente_id, origem, origem_id) WHERE origem_id IS NOT NULL');

        $pdo->exec("CREATE TABLE listas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            cor TEXT NOT NULL DEFAULT '#7c5cff',
            criado_em TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE status (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lista_id INTEGER NOT NULL REFERENCES listas(id) ON DELETE CASCADE,
            nome TEXT NOT NULL,
            cor TEXT NOT NULL DEFAULT '#94a3b8',
            tipo TEXT NOT NULL CHECK (tipo IN ('aberto','andamento','fechado')),
            ordem INTEGER NOT NULL
        )");
        $pdo->exec("CREATE TABLE tarefas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lista_id INTEGER NOT NULL REFERENCES listas(id),
            cliente_id INTEGER REFERENCES clientes(id) ON DELETE SET NULL,
            status_id INTEGER NOT NULL REFERENCES status(id),
            titulo TEXT NOT NULL,
            descricao TEXT NOT NULL DEFAULT '',
            prioridade INTEGER NOT NULL DEFAULT 3 CHECK (prioridade BETWEEN 1 AND 4),
            responsavel_id INTEGER REFERENCES usuarios(id),
            prazo TEXT,
            turno TEXT CHECK (turno IS NULL OR turno IN ('manha','tarde','fechamento')),
            origem TEXT NOT NULL DEFAULT 'manual',
            origem_chave TEXT,
            criado_por INTEGER,
            criado_em TEXT NOT NULL,
            atualizado_em TEXT NOT NULL,
            concluida_em TEXT
        )");
        $pdo->exec('CREATE UNIQUE INDEX idx_tarefas_origem ON tarefas(origem_chave) WHERE origem_chave IS NOT NULL');
        $pdo->exec('CREATE INDEX idx_tarefas_resp_prazo ON tarefas(responsavel_id, prazo)');
        $pdo->exec('CREATE INDEX idx_tarefas_cliente ON tarefas(cliente_id)');
        $pdo->exec('CREATE TABLE etiquetas (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT NOT NULL UNIQUE, cor TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE tarefa_etiquetas (
            tarefa_id INTEGER NOT NULL REFERENCES tarefas(id) ON DELETE CASCADE,
            etiqueta_id INTEGER NOT NULL REFERENCES etiquetas(id) ON DELETE CASCADE,
            PRIMARY KEY (tarefa_id, etiqueta_id)
        )');
        $pdo->exec('CREATE TABLE tarefa_itens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tarefa_id INTEGER NOT NULL REFERENCES tarefas(id) ON DELETE CASCADE,
            texto TEXT NOT NULL,
            feito INTEGER NOT NULL DEFAULT 0,
            ordem INTEGER NOT NULL
        )');
        $pdo->exec("CREATE TABLE tarefa_atividades (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tarefa_id INTEGER NOT NULL REFERENCES tarefas(id) ON DELETE CASCADE,
            usuario_id INTEGER,
            tipo TEXT NOT NULL,
            texto TEXT NOT NULL DEFAULT '',
            criado_em TEXT NOT NULL
        )");
        $pdo->exec('CREATE INDEX idx_atividades_tarefa ON tarefa_atividades(tarefa_id, id)');
        $pdo->exec("CREATE TABLE automacoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            ativa INTEGER NOT NULL DEFAULT 1,
            gatilho TEXT NOT NULL,
            gatilho_config TEXT NOT NULL DEFAULT '{}',
            condicoes TEXT NOT NULL DEFAULT '{}',
            acoes TEXT NOT NULL DEFAULT '[]',
            criado_em TEXT NOT NULL,
            ultima_execucao TEXT
        )");
        $pdo->exec("CREATE TABLE automacao_execucoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            automacao_id INTEGER NOT NULL REFERENCES automacoes(id) ON DELETE CASCADE,
            chave TEXT NOT NULL,
            resumo TEXT NOT NULL DEFAULT '',
            criado_em TEXT NOT NULL,
            UNIQUE (automacao_id, chave)
        )");
        $pdo->exec('CREATE TABLE meta (chave TEXT PRIMARY KEY, valor TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE configuracoes (chave TEXT PRIMARY KEY, valor TEXT NOT NULL)');

        // ---- padroes: duas listas e as automacoes que montam a rotina diaria
        $agora = date(DATE_ATOM);
        $lista = $pdo->prepare('INSERT INTO listas (id, nome, cor, criado_em) VALUES (?, ?, ?, ?)');
        $status = $pdo->prepare('INSERT INTO status (lista_id, nome, cor, tipo, ordem) VALUES (?, ?, ?, ?, ?)');
        $lista->execute([1, 'Rotina diária', '#7c5cff', $agora]);
        $lista->execute([2, 'Geral', '#22b8cf', $agora]);

        foreach ([
            1 => [['A fazer', '#94a3b8', 'aberto'], ['Em andamento', '#3b82f6', 'andamento'], ['Concluído', '#22c55e', 'fechado']],
            2 => [['A fazer', '#94a3b8', 'aberto'], ['Em andamento', '#3b82f6', 'andamento'], ['Em revisão', '#f59e0b', 'andamento'], ['Concluído', '#22c55e', 'fechado']],
        ] as $listaId => $itens) {
            foreach ($itens as $i => [$nome, $cor, $tipo]) {
                $status->execute([$listaId, $nome, $cor, $tipo, $i + 1]);
            }
        }

        $auto = $pdo->prepare('INSERT INTO automacoes (nome, ativa, gatilho, gatilho_config, condicoes, acoes, criado_em) VALUES (?, 1, ?, ?, ?, ?, ?)');
        $semana = ['dias' => ['segunda', 'terca', 'quarta', 'quinta', 'sexta'], 'hora' => '05:00'];
        $json = static fn (array $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE);
        $auto->execute(['Rotina diária dos clientes', 'agenda', $json($semana), '{}', $json([
            ['tipo' => 'criar_tarefa', 'lista_id' => 1, 'titulo' => 'Analisar {cliente}', 'por' => 'cliente_agendado', 'turno' => 'cliente', 'prazo' => 'hoje', 'prioridade' => 3],
        ]), $agora]);

        foreach ([
            'Checklist da manhã' => ['manha', ['Atenção nos grupos', 'Verificar contas e saldo', 'Organizar prioridades do dia', 'Executar otimizações, testes e publicações']],
            'Checklist da tarde' => ['tarde', ['Atenção nos grupos', 'Executar otimizações, testes e publicações', 'Responder clientes pendentes']],
            'Checklist do fechamento' => ['fechamento', ['Registrar resultados do dia', 'Anotar pendências para amanhã']],
        ] as $nome => [$turno, $titulos]) {
            $acoes = array_map(static fn (string $t): array => ['tipo' => 'criar_tarefa', 'lista_id' => 1, 'titulo' => $t, 'por' => 'gestor', 'turno' => $turno, 'prazo' => 'hoje', 'prioridade' => 3], $titulos);
            $auto->execute([$nome, 'agenda', $json($semana), '{}', $json($acoes), $agora]);
        }

        $auto->execute(['Tarefa atrasada ganha prioridade alta', 'prazo_vencido', '{}', '{}', $json([
            ['tipo' => 'definir_prioridade', 'prioridade' => 2],
            ['tipo' => 'adicionar_etiqueta', 'nome' => 'atrasada'],
        ]), $agora]);
    }
}
