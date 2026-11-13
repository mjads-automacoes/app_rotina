<?php

declare(strict_types=1);

/** Falta de permissao (HTTP 403). */
class AcessoNegado extends RuntimeException
{
}

function e(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Icones de traco (24x24), herdam a cor do texto. */
function icone(string $nome): string
{
    static $i = [
        'rotina' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'tarefas' => '<rect x="3" y="4" width="5" height="16" rx="1.5"/><rect x="10" y="4" width="5" height="10" rx="1.5"/><rect x="17" y="4" width="4" height="13" rx="1.5"/>',
        'clientes' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'automacoes' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'historico' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'importar' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'equipe' => '<path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/>',
        'conta' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
        'sol' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'lua' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'menu' => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'sair' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'mais' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'busca' => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'atualizar' => '<polyline points="23 4 23 10 17 10"/><path d="M20.5 15a9 9 0 1 1-2.1-9.4L23 10"/>',
    ];

    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($i[$nome] ?? '') . '</svg>';
}

/** Logos coloridas das plataformas de anuncio (so Google e Meta). */
function logo_plataforma(string $chave): string
{
    if ($chave === 'google') {
        return '<svg class="logo-plat" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6.1 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.6-.4-3.9z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.700 15.100 19 12 24 12c3.100 0 5.800 1.200 8 3l5.700-5.700C34 6.100 29.300 4 24 4 16.300 4 9.700 8.300 6.300 14.700z"/><path fill="#4CAF50" d="M24 44c5.200 0 9.900-2 13.400-5.200l-6.200-5.200C29.200 35.100 26.700 36 24 36c-5.200 0-9.600-3.300-11.300-7.900l-6.500 5C9.500 39.600 16.200 44 24 44z"/><path fill="#1976D2" d="M43.600 20.100H42V20H24v8h11.300c-.8 2.200-2.200 4.200-4.100 5.600l6.200 5.200C37 39.200 44 34 44 24c0-1.300-.1-2.600-.4-3.900z"/></svg>';
    }

    if ($chave === 'meta') {
        return '<svg class="logo-plat" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 12c-1.800-2.600-3.400-4.500-5.400-4.500C4.400 7.500 3 9.500 3 12s1.400 4.500 3.600 4.500c2 0 3.600-1.900 5.400-4.500zm0 0c1.800 2.600 3.400 4.500 5.400 4.500 2.200 0 3.600-2 3.600-4.500s-1.400-4.500-3.600-4.500c-2 0-3.600 1.900-5.400 4.500z" stroke="#0A7CFF" stroke-width="2.300" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    return '';
}

/** Menu de selecao dos dias da rotina (varias opcoes). */
function seletor_dias(array $marcados): string
{
    $dias = ['segunda' => 'Segunda-feira', 'terca' => 'Terça-feira', 'quarta' => 'Quarta-feira', 'quinta' => 'Quinta-feira', 'sexta' => 'Sexta-feira'];
    $curto = ['segunda' => 'Seg', 'terca' => 'Ter', 'quarta' => 'Qua', 'quinta' => 'Qui', 'sexta' => 'Sex'];
    $sel = array_values(array_filter(array_keys($dias), static fn (string $d): bool => in_array($d, $marcados, true)));
    $rotulo = count($sel) === 0 ? 'Selecione os dias' : (count($sel) === 5 ? 'Segunda a sexta' : implode(', ', array_map(static fn (string $d): string => $curto[$d], $sel)));
    $h = '<div class="campo-multi"><span class="campo-rotulo">Dias da rotina</span><details class="multi" data-multi><summary><span data-multi-rotulo>' . e($rotulo) . '</span></summary><div class="multi-lista"><input type="hidden" name="dias_enviado" value="1">';

    foreach ($dias as $chave => $nome) {
        $h .= '<label class="check"><input type="checkbox" name="dias[]" value="' . e($chave) . '" data-curto="' . e($curto[$chave]) . '"' . (in_array($chave, $sel, true) ? ' checked' : '') . '> ' . e($nome) . '</label>';
    }

    return $h . '</div></details></div>';
}

/** Sessao em memoria (nos testes) ou ligada a $_SESSION (index.php). */
final class Sessao
{
    public array $d = [];
    public int $renovacoes = 0;

    public function __construct(private bool $nativa = false)
    {
    }

    /** Novo id de sessao (contra fixacao de sessao) ao entrar e sair. */
    public function renovar(): void
    {
        $this->renovacoes++;

        if ($this->nativa && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}

final class Request
{
    public array $headers = [];

    public function __construct(
        public string $metodo,
        public string $caminho,
        public array $query,
        public array $post,
        array $headers,
        public string $ip,
        public Sessao $sessao,
    ) {
        foreach ($headers as $k => $v) {
            $this->headers[strtolower((string) $k)] = (string) $v;
        }
    }

    public function header(string $nome): string
    {
        return $this->headers[strtolower($nome)] ?? '';
    }
}

final class Response
{
    public function __construct(public int $status = 200, public array $headers = [], public string $body = '')
    {
    }

    public static function html(string $corpo, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $corpo);
    }

    public static function json(array $dados, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'], (string) json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function redirect(string $para): self
    {
        return new self(302, ['Location' => $para], '');
    }
}

final class View
{
    public static function render(string $nome, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__) . '/views/' . $nome . '.php';

        return (string) ob_get_clean();
    }
}
