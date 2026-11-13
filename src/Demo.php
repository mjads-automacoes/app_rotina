<?php

declare(strict_types=1);

/**
 * Dados FICTICIOS de demonstracao: 19 clientes (pelo menos 6 por dia util na rotina do dono), a semana da rotina, tarefas gerais, notas e uma automacao.
 * Usado pelo preview local (scripts/seed-demo.php) e, a pedido do dono, na versao online (scripts/demo.php).
 * Tudo o que e criado fica anotado em meta.demo, para que apagar() remova so isso e nada do que for real.
 */
final class Demo
{
    private const META = 'demo';

    public static function carregado(PDO $pdo): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM meta WHERE chave = ?');
        $st->execute([self::META]);

        return (bool) $st->fetchColumn();
    }

    /**
     * Cria os dados ficticios. $dono recebe os clientes; $outro (opcional) recebe os da "Ana". Sem $outro, vai tudo para $dono.
     * Retorna false (sem fazer nada) se ja estiver carregado.
     */
    public static function carregar(Nucleo $n, array $dono, ?array $outro = null): bool
    {
        if (self::carregado($n->pdo)) {
            return false;
        }

        $outro ??= $dono;
        $pdo = $n->pdo;
        $tarefaAntes = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM tarefas')->fetchColumn();
        $automacaoAntes = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM automacoes')->fetchColumn();

        $clientes = [
            // [gestor, nome, empresa, descricao, dias, turno, status, plataformas, orcamento, contato, telefone, observacoes]
            [$dono, 'BILL BORRACHA', 'Bill Borracha LTDA', 'Revisar campanhas e registrar ajustes', ['segunda', 'quinta'], 'manha', 'ativo', ['google', 'meta'], 'R$ 3.000/mês', 'Bill', '(11) 91234-5678', 'Prefere contato por WhatsApp.'],
            [$dono, 'DB CAMARÕES', 'DB Camarões', 'Conferir orçamento e conversões', ['segunda', 'quinta'], 'manha', 'ativo', ['meta'], 'R$ 1.800/mês', 'Davi', '(11) 98888-1111', ''],
            [$dono, 'LAVANDERIA PARAISO', 'Lavanderia Paraíso', 'Otimizar públicos e criativos', ['segunda', 'quarta'], 'tarde', 'ativo', ['google'], 'R$ 1.200/mês', 'Paula', '(11) 97777-2222', ''],
            [$dono, 'AUTO CENTER PRAIA GRANDE', 'Auto Center PG', 'Relatório e ajustes de lance', ['terca', 'sexta'], 'manha', 'ativo', ['google', 'meta'], 'R$ 4.500/mês', 'Marcos', '(13) 96666-3333', 'Reunião mensal toda primeira sexta.'],
            [$dono, 'CLÍNICA SORRISO PLENO', 'Sorriso Pleno', 'Analisar leads da semana', ['terca', 'sexta'], 'tarde', 'ativo', ['meta', 'tiktok'], 'R$ 2.500/mês', 'Dra. Helena', '(11) 95555-4444', ''],
            [$dono, 'PET SHOP AMIGO FIEL', 'Amigo Fiel', 'Conferir campanhas de banho e tosa', ['quarta', 'sexta'], 'tarde', 'ativo', ['meta'], 'R$ 900/mês', 'Rafa', '(11) 94444-5555', ''],
            [$dono, 'STUDIO BELLA FORMA', 'Bella Forma', 'Cliente sem dia marcado ainda', [], 'manha', 'ativo', ['meta'], '', 'Renata', '', ''],
            [$dono, 'IMOBILIÁRIA HORIZONTE', 'Horizonte Imóveis', 'Em onboarding (fica fora da rotina)', ['segunda'], 'manha', 'novo', ['google'], 'R$ 6.000/mês', 'Jorge', '(11) 93333-6666', 'Aguardando acesso às contas.'],
            [$dono, 'ÓTICA VISÃO CLARA', 'Visão Clara', 'Campanha de óculos de grau', ['segunda', 'terca'], 'manha', 'ativo', ['google'], 'R$ 1.600/mês', 'Sérgio', '(11) 90000-1001', ''],
            [$dono, 'FARMÁCIA BEM ESTAR', 'Bem Estar', 'Ofertas da semana no Instagram', ['segunda', 'quarta'], 'tarde', 'ativo', ['meta'], 'R$ 2.200/mês', 'Cláudia', '(11) 90000-1002', ''],
            [$dono, 'ESCOLA DE IDIOMAS FALA FÁCIL', 'Fala Fácil', 'Captação de alunos para o semestre', ['terca', 'quinta'], 'manha', 'ativo', ['google', 'meta'], 'R$ 3.200/mês', 'Tiago', '(11) 90000-1003', ''],
            [$dono, 'CONSTRUTORA ALICERCE', 'Alicerce', 'Leads de lançamento imobiliário', ['terca', 'sexta'], 'tarde', 'ativo', ['google'], 'R$ 8.000/mês', 'Fernanda', '(11) 90000-1004', 'Relatório quinzenal.'],
            [$dono, 'BARBEARIA DOM BIGODE', 'Dom Bigode', 'Agendamentos pelo WhatsApp', ['quarta', 'quinta'], 'manha', 'ativo', ['meta'], 'R$ 700/mês', 'Caio', '(11) 90000-1005', ''],
            [$dono, 'DOCERIA MEL & CIA', 'Mel & Cia', 'Encomendas de fim de semana', ['quarta', 'sexta'], 'tarde', 'ativo', ['meta'], 'R$ 800/mês', 'Bruna', '(11) 90000-1006', ''],
            [$dono, 'ADVOCACIA SOUZA LIMA', 'Souza Lima', 'Pesquisa: palavras-chave e lances', ['quinta', 'sexta'], 'manha', 'ativo', ['google'], 'R$ 2.800/mês', 'Dr. André', '(11) 90000-1007', ''],
            [$dono, 'PADARIA PÃO DOURADO', 'Pão Dourado', 'Promoções do café da manhã', ['segunda', 'quarta'], 'manha', 'ativo', ['meta'], 'R$ 600/mês', 'Seu Zé', '(11) 90000-1008', ''],
            [$dono, 'HOTEL MIRANTE DO MAR', 'Mirante do Mar', 'Reservas para a alta temporada', ['terca', 'quinta'], 'tarde', 'ativo', ['google', 'meta'], 'R$ 5.000/mês', 'Lúcia', '(13) 90000-1009', ''],
            [$outro, 'ACADEMIA CORPO E MENTE', 'Corpo e Mente', 'Revisar campanhas de matrícula', ['segunda', 'quarta'], 'manha', 'ativo', ['meta'], 'R$ 2.000/mês', 'Léo', '(11) 92222-7777', ''],
            [$outro, 'RESTAURANTE SABOR DA SERRA', 'Sabor da Serra', 'Delivery: ajustar horários', ['terca', 'quinta'], 'tarde', 'ativo', ['google', 'meta'], 'R$ 1.500/mês', 'Marta', '(11) 91111-8888', ''],
        ];
        $ids = [];

        foreach ($clientes as [$g, $nome, $empresa, $desc, $dias, $turno, $status, $plat, $orc, $contato, $tel, $obs]) {
            $ids[$nome] = $n->clientes->criar($g, ['nome' => $nome, 'empresa' => $empresa, 'descricao' => $desc, 'dias' => $dias, 'turno' => $turno, 'status' => $status, 'plataformas' => $plat,
                'orcamento' => $orc, 'contato_nome' => $contato, 'contato_telefone' => $tel, 'observacoes' => $obs, 'link' => 'https://exemplo.com/' . strtolower((string) preg_replace('/\W+/', '-', $nome))]);
        }

        $n->clientes->notaAdicionar($dono, $ids['BILL BORRACHA'], 'Cliente pediu relatório semanal por WhatsApp.');
        $n->clientes->notaAdicionar($dono, $ids['BILL BORRACHA'], 'Aprovou aumento de verba para novembro.');

        // A semana inteira de rotina, gerada pelas proprias automacoes (como acontece sozinho todo dia as 05:00)
        $segunda = new DateTimeImmutable('monday this week 05:00');
        $agenda = array_values(array_filter($n->automacoes->listar(), static fn (array $a): bool => $a['gatilho'] === 'agenda'));

        for ($d = 0; $d < 5; $d++) {
            foreach ($agenda as $a) {
                $n->automacoes->executarAgora(Nucleo::sistema(), $a['id'], $segunda->modify("+{$d} days"));
            }
        }

        // Dias que ja passaram: boa parte concluida, com notas (so nas tarefas criadas aqui)
        $hoje = new DateTimeImmutable('today');

        foreach ($n->tarefas->listar($dono, [], 2000) as $i => $t) {
            if ($t['id'] > $tarefaAntes && $t['prazo'] !== null && $t['prazo'] < $hoje->format('Y-m-d') && $i % 4 !== 0) {
                $n->tarefas->moverPorTipo($dono, $t['id'], 'fechado');

                if ($t['cliente_id'] !== null && $i % 3 === 0) {
                    $n->tarefas->comentar($dono, $t['id'], ['Ajustei o orçamento diário', 'Pausei 2 anúncios com CPA alto', 'Subi 3 criativos novos', 'Conferi conversões e públicos'][$i % 4]);
                }
            }
        }

        // Tarefas gerais (lista Geral) com status, prioridade, etiquetas, checklist e comentarios
        $statusGeral = array_column($n->listas->obter(2)['status'], 'id');
        $amanha = $hoje->modify('+1 day')->format('Y-m-d');
        $ontem = $hoje->modify('-1 day')->format('Y-m-d');
        $geral = [
            [$dono, 'Montar relatório mensal do Bill Borracha', 'BILL BORRACHA', 2, $amanha, ['relatório'], 1, ['Puxar dados do Google Ads', 'Puxar dados do Meta Ads', 'Montar o PDF', 'Enviar para o cliente']],
            [$dono, 'Subir campanha de Black Friday', 'DB CAMARÕES', 1, $hoje->modify('+3 days')->format('Y-m-d'), ['campanha', 'meta ads'], 0, ['Criativos aprovados', 'Público definido']],
            [$dono, 'Revisar acesso às contas do Horizonte', 'IMOBILIÁRIA HORIZONTE', 3, $ontem, ['onboarding'], 0, []],
            [$dono, 'Reunião mensal Auto Center PG', 'AUTO CENTER PRAIA GRANDE', 3, $hoje->modify('+2 days')->format('Y-m-d'), ['reunião'], 2, ['Preparar apresentação']],
            [$outro, 'Ajustar horários de entrega no Google Meu Negócio', 'RESTAURANTE SABOR DA SERRA', 3, $amanha, ['google'], 0, []],
            [$outro, 'Criar novos criativos de matrícula', 'ACADEMIA CORPO E MENTE', 2, $hoje->modify('+4 days')->format('Y-m-d'), ['criativo'], 1, ['Roteiro dos vídeos', 'Gravar', 'Editar']],
            [$dono, 'Atualizar planilha de churn', null, 4, null, [], 3, []],
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

        $primeira = $pdo->prepare("SELECT MIN(id) FROM tarefas WHERE id > ? AND origem = 'automacao' AND cliente_id IS NOT NULL");
        $primeira->execute([$tarefaAntes]);

        if ($alvo = (int) $primeira->fetchColumn()) {
            $n->tarefas->comentar($dono, $alvo, 'Cliente confirmou que quer o relatório até quinta.');
        }

        // Uma automacao de exemplo
        $n->automacoes->criar($dono, ['nome' => 'Ao concluir uma tarefa Geral, registrar no comentário', 'gatilho' => 'status_mudou', 'gatilho_config' => ['para_tipo' => 'fechado'], 'condicoes' => ['lista_id' => 2],
            'acoes' => [['tipo' => 'comentar', 'texto' => 'Concluída em {data}. Bom trabalho!']]]);

        $registro = [
            'clientes' => array_values($ids),
            'tarefas' => [$tarefaAntes + 1, (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM tarefas')->fetchColumn()],
            'automacoes' => array_map('intval', $pdo->query('SELECT id FROM automacoes WHERE id > ' . $automacaoAntes)->fetchAll(PDO::FETCH_COLUMN)),
        ];
        $pdo->prepare('INSERT OR REPLACE INTO meta (chave, valor) VALUES (?, ?)')->execute([self::META, json_encode($registro)]);

        return true;
    }

    /**
     * Apaga so o que carregar() criou: os clientes ficticios, as tarefas deles (inclusive as que a rotina gerou depois),
     * as tarefas criadas durante a carga e a automacao de exemplo. Retorna quantos clientes apagou (0 se nao havia demo).
     */
    public static function apagar(Nucleo $n): int
    {
        $pdo = $n->pdo;
        $st = $pdo->prepare('SELECT valor FROM meta WHERE chave = ?');
        $st->execute([self::META]);
        $json = $st->fetchColumn();

        if ($json === false) {
            return 0;
        }

        $r = json_decode((string) $json, true);
        $clientes = array_map('intval', $r['clientes'] ?? []);
        [$de, $ate] = array_map('intval', $r['tarefas'] ?? [0, -1]);
        $pdo->beginTransaction();

        try {
            if ($clientes !== []) {
                $in = implode(',', array_fill(0, count($clientes), '?'));
                $pdo->prepare("DELETE FROM tarefas WHERE cliente_id IN ($in)")->execute($clientes);
                $pdo->prepare("DELETE FROM clientes WHERE id IN ($in)")->execute($clientes);
            }

            $pdo->prepare('DELETE FROM tarefas WHERE id BETWEEN ? AND ?')->execute([$de, $ate]);

            foreach (array_map('intval', $r['automacoes'] ?? []) as $a) {
                $pdo->prepare('DELETE FROM automacoes WHERE id = ?')->execute([$a]);
            }

            $pdo->prepare('DELETE FROM meta WHERE chave = ?')->execute([self::META]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return count($clientes);
    }
}
