<?php

declare(strict_types=1);

// Configuracao unica. Tudo pode ser trocado por variavel de ambiente (Docker/Easypanel).
// O NOME do produto fica so aqui: para renomear a estrutura depois, mude ROTINA_APP_NAME (ou o valor abaixo).

return [
    'app_name' => getenv('ROTINA_APP_NAME') ?: 'Rotina',
    'data_dir' => getenv('ROTINA_DATA_DIR') ?: dirname(__DIR__) . '/data',
    'timezone' => getenv('ROTINA_TIMEZONE') ?: 'America/Fortaleza',
    // cookie de sessao so por HTTPS (ligue em producao atras do Traefik/HTTPS)
    'cookie_secure' => in_array(strtolower((string) getenv('ROTINA_COOKIE_SECURE')), ['1', 'true', 'yes'], true),
    // tamanho minimo da senha. Padrao 1 (pedido do dono, fase de testes). ANTES DE PUBLICAR: ROTINA_SENHA_MIN=8 e troque as senhas.
    'senha_min' => max(1, (int) (getenv('ROTINA_SENHA_MIN') ?: 1)),
    'max_tentativas_login' => 5,
    'janela_bloqueio_min' => 15,
];
