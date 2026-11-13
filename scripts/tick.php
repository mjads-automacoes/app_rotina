<?php

declare(strict_types=1);

// Roda as automacoes que estao na hora (agendadas e prazos vencidos). Agende no cron do servidor, a cada minuto:
//   * * * * * php /var/www/html/scripts/tick.php
// Seguro de rodar quantas vezes for: nao duplica nada.

require dirname(__DIR__) . '/src/bootstrap.php';

$n = Nucleo::montar(Db::padrao());
$r = $n->automacoes->tick(new DateTimeImmutable('now'));
echo date('Y-m-d H:i:s') . ' automacoes: ' . $r['executadas'] . ' executada(s), ' . $r['tarefas_criadas'] . " tarefa(s) criada(s)\n";
