<?php

declare(strict_types=1);

// Dados de EXEMPLO para o preview local (nao use em producao). Idempotente: se ja existe usuario, nao faz nada.
// php -d extension=pdo_sqlite -d extension=sqlite3 scripts/seed-demo.php

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Db::padrao();
$n = Nucleo::montar($pdo);

if ($n->auth->total() > 0) {
    echo "Banco ja tem usuarios; nada a fazer.\n";
    exit(0);
}

$admin = $n->auth->buscar($n->auth->criar('Mateus (admin)', 'admin', '1', 'admin'));
$ana = $n->auth->buscar($n->auth->criar('Ana Souza', 'ana', '1', 'gestor'));

$clientes = [
    // [gestor, nome, empresa, descricao, dias, turno, status, plataformas, orcamento, contato, telefone, observacoes]
    [$admin, 'BILL BORRACHA', 'Bill Borracha LTDA', 'Revisar campanhas e registrar ajustes', ['segunda', 'quinta'], 'manha', 'ativo', ['google', 'meta'], 'R$ 3.000/mês', 'Bill', '(11) 91234-5678', 'Prefere contato por WhatsApp.'],
    [$admin, 'DB CAMARÕES', 'DB Camarões', 'Conferir orçamento e conversões', ['segunda', 'quinta'], 'manha', 'ativo', ['meta'], 'R$ 1.800/mês', 'Davi', '(11) 98888-1111', ''],
    [$admin, 'LAVANDERIA PARAISO', 'Lavanderia Paraíso', 'Otimizar públicos e criativos', ['segunda', 'quarta'], 'tarde', 'ativo', ['google'], 'R$ 1.200/mês', 'Paula', '(11) 97777-2222', ''],
    [$admin, 'AUTO CENTER PRAIA GRANDE', 'Auto Center PG', 'Relatório e ajustes de lance', ['terca', 'sexta'], 'manha', 'ativo', ['google', 'meta'], 'R$ 4.500/mês', 'Marcos', '(13) 96666-3333', 'Reunião mensal toda primeira sexta.'],
    [$admin, 'CLÍNICA SORRISO PLENO', 'Sorriso Pleno', 'Analisar leads da semana', ['terca', 'sexta'], 'tarde', 'ativo', ['meta', 'tiktok'], 'R$ 2.500/mês', 'Dra. Helena', '(11) 95555-4444', ''],
    [$admin, 'PET SHOP AMIGO FIEL', 'Amigo Fiel', 'Fechamento: resultados do dia', ['quarta', 'sexta'], 'fechamento', 'ativo', ['meta'], 'R$ 900/mês', 'Rafa', '(11) 94444-5555', ''],
    [$admin, 'STUDIO BELLA FORMA', 'Bella Forma', 'Cliente sem dia marcado ainda', [], 'manha', 'ativo', ['meta'], '', 'Renata', '', ''],
    [$admin, 'IMOBILIÁRIA HORIZONTE', 'Horizonte Imóveis', 'Em onboarding (fica fora da rotina)', ['segunda'], 'manha', 'novo', ['google'], 'R$ 6.000/mês', 'Jorge', '(11) 93333-6666', 'Aguardando acesso às contas.'],
    [$ana, 'ACADEMIA CORPO E MENTE', 'Corpo e Mente', 'Revisar campanhas de matrícula', ['segunda', 'quarta'], 'manha', 'ativo', ['meta'], 'R$ 2.000/mês', 'Léo', '(11) 92222-7777', ''],
    [$ana, 'RESTAURANTE SABOR DA SERRA', 'Sabor da Serra', 'Delivery: ajustar horários', ['terca', 'quinta'], 'tarde', 'ativo', ['google', 'meta'], 'R$ 1.500/mês', 'Marta', '(11) 91111-8888', ''],
];
$ids = [];

foreach ($clientes as [$g, $nome, $empresa, $desc, $dias, $turno, $status, $plat, $orc, $contato, $tel, $obs]) {
    $ids[$nome] = $n->clientes->criar($g, ['nome' => $nome, 'empresa' => $empresa, 'descricao' => $desc, 'dias' => $dias, 'turno' => $turno, 'status' => $status, 'plataformas' => $plat,
        'orcamento' => $orc, 'contato_nome' => $contato, 'contato_telefone' => $tel, 'observacoes' => $obs, 'link' => 'https://exemplo.com/' . strtolower(preg_replace('/\W+/', '-', $nome))]);
}

$n->clientes->notaAdicionar($admin, $ids['BILL BORRACHA'], 'Cliente pediu relatório semanal por WhatsApp.');
$n->clientes->notaAdicionar($admin, $ids['BILL BORRACHA'], 'Aprovou aumento de verba para novembro.');

// A semana inteira de rotina, gerada pelas proprias automacoes (como acontece sozinho todo dia as 05:00)
$segunda = new DateTimeImmutable('monday this week 05:00');
$agenda = array_values(array_filter($n->automacoes->listar(), static fn (array $a): bool => $a['gatilho'] === 'agenda'));

for ($d = 0; $d < 5; $d++) {
    $dia = $segunda->modify("+{$d} days");

    foreach ($agenda as $a) {
        $n->automacoes->executarAgora(Nucleo::sistema(), $a['id'], $dia);
    }
}

// Dias que ja passaram: boa parte concluida, com notas
$hoje = new DateTimeImmutable('today');

foreach ($n->tarefas->listar($admin, [], 2000) as $i => $t) {
    if ($t['prazo'] !== null && $t['prazo'] < $hoje->format('Y-m-d') && $i % 4 !== 0) {
        $n->tarefas->moverPorTipo($admin, $t['id'], 'fechado');

        if ($t['cliente_id'] !== null && $i % 3 === 0) {
            $n->tarefas->comentar($admin, $t['id'], ['Ajustei o orçamento diário', 'Pausei 2 anúncios com CPA alto', 'Subi 3 criativos novos', 'Conferi conversões e públicos'][$i % 4]);
        }
    }
}

// Tarefas gerais (lista Geral) com status, prioridade, etiquetas, checklist e comentarios
$statusGeral = array_column($n->listas->obter(2)['status'], 'id');
$amanha = $hoje->modify('+1 day')->format('Y-m-d');
$ontem = $hoje->modify('-1 day')->format('Y-m-d');
$geral = [
    [$admin, 'Montar relatório mensal do Bill Borracha', 'BILL BORRACHA', 2, $amanha, ['relatório'], 1, ['Puxar dados do Google Ads', 'Puxar dados do Meta Ads', 'Montar o PDF', 'Enviar para o cliente']],
    [$admin, 'Subir campanha de Black Friday', 'DB CAMARÕES', 1, $hoje->modify('+3 days')->format('Y-m-d'), ['campanha', 'meta ads'], 0, ['Criativos aprovados', 'Público definido']],
    [$admin, 'Revisar acesso às contas do Horizonte', 'IMOBILIÁRIA HORIZONTE', 3, $ontem, ['onboarding'], 0, []],
    [$admin, 'Reunião mensal Auto Center PG', 'AUTO CENTER PRAIA GRANDE', 3, $hoje->modify('+2 days')->format('Y-m-d'), ['reunião'], 2, ['Preparar apresentação']],
    [$ana, 'Ajustar horários de entrega no Google Meu Negócio', 'RESTAURANTE SABOR DA SERRA', 3, $amanha, ['google'], 0, []],
    [$ana, 'Criar novos criativos de matrícula', 'ACADEMIA CORPO E MENTE', 2, $hoje->modify('+4 days')->format('Y-m-d'), ['criativo'], 1, ['Roteiro dos vídeos', 'Gravar', 'Editar']],
    [$admin, 'Atualizar planilha de churn', null, 4, null, [], 3, []],
];

foreach ($geral as [$u, $titulo, $cliente, $prio, $prazo, $etiquetas, $coluna, $itens]) {
    $id = $n->tarefas->criar($u, ['titulo' => $titulo, 'lista_id' => 2, 'cliente_id' => $cliente !== null ? $ids[$cliente] : null, 'prioridade' => $prio, 'prazo' => $prazo, 'etiquetas' => $etiquetas]);

    foreach ($itens as $k => $texto) {
        $item = $n->tarefas->itemAdicionar($u, $id, $texto);

        if ($k === 0 && $coluna > 0) {
            $n->tarefas->itemAlternar($u, $item, true);
        }
    }

    if ($coluna > 0) {
        $n->tarefas->mover($u, $id, (int) $statusGeral[$coluna]);
    }
}

$n->tarefas->comentar($admin, 1 + (int) $pdo->query("SELECT COUNT(*) FROM tarefas WHERE origem = 'automacao'")->fetchColumn(), 'Cliente confirmou que quer o relatório até quinta.');

// Uma automacao de exemplo criada pelo admin
$n->automacoes->criar($admin, ['nome' => 'Ao concluir uma tarefa Geral, registrar no comentário', 'gatilho' => 'status_mudou', 'gatilho_config' => ['para_tipo' => 'fechado'], 'condicoes' => ['lista_id' => 2],
    'acoes' => [['tipo' => 'comentar', 'texto' => 'Concluída em {data}. Bom trabalho!']]]);

echo "Pronto: login admin (administrador) e login ana (gestora), senha 1\n";
