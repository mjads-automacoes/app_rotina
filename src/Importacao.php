<?php

declare(strict_types=1);

/**
 * Importacao do ClickUp: SOMENTE LEITURA (so faz GET). Traz os clientes e comentarios de uma lista para dentro da estrutura.
 * O token fica criptografado (AES-256-GCM) no banco e nunca volta para a tela.
 */
final class Importacao
{
    private const BASE = 'https://api.clickup.com/api/v2';

    /** @var callable(string,string):array{status:int,json:?array} */
    private $transporte;

    public function __construct(private PDO $pdo, private Clientes $clientes, ?callable $transporte = null, private ?string $chave = null)
    {
        $this->transporte = $transporte ?? [$this, 'transporteReal'];
    }

    // ---------- token ----------

    public function temToken(): bool
    {
        return (bool) $this->pdo->query("SELECT 1 FROM configuracoes WHERE chave = 'clickup_token'")->fetchColumn();
    }

    public function salvarToken(string $token): void
    {
        $token = trim($token);

        if ($token === '' || strlen($token) > 200 || preg_match('/\s/', $token)) {
            throw new InvalidArgumentException('Informe o token do ClickUp (começa com pk_).');
        }

        $iv = random_bytes(12);
        $cifrado = openssl_encrypt($token, 'aes-256-gcm', $this->chaveBinaria(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cifrado === false) {
            throw new RuntimeException('Não foi possível proteger o token.');
        }

        $this->pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('clickup_token', ?) ON CONFLICT (chave) DO UPDATE SET valor = excluded.valor")
            ->execute([base64_encode($iv . $tag . $cifrado)]);
    }

    public function removerToken(): void
    {
        $this->pdo->exec("DELETE FROM configuracoes WHERE chave = 'clickup_token'");
    }

    private function token(): string
    {
        $bruto = $this->pdo->query("SELECT valor FROM configuracoes WHERE chave = 'clickup_token'")->fetchColumn();

        if ($bruto === false) {
            throw new RuntimeException('Conecte o ClickUp primeiro: cole o token em Importar.');
        }

        $b = base64_decode((string) $bruto, true) ?: '';
        $token = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $this->chaveBinaria(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));

        if ($token === false) {
            throw new RuntimeException('Não foi possível ler o token salvo. Cole o token de novo.');
        }

        return $token;
    }

    private function chaveBinaria(): string
    {
        if ($this->chave === null || $this->chave === '') {
            $env = (string) getenv('ROTINA_SECRET');
            $arquivo = rtrim((string) rotina_config('data_dir'), '/\\') . '/chave.key';

            if ($env !== '') {
                $this->chave = $env;
            } else {
                if (!is_file($arquivo)) {
                    if (!is_dir(dirname($arquivo))) {
                        mkdir(dirname($arquivo), 0775, true);
                    }

                    file_put_contents($arquivo, bin2hex(random_bytes(32)), LOCK_EX);
                    @chmod($arquivo, 0600);
                }

                $this->chave = trim((string) file_get_contents($arquivo));
            }
        }

        return hash('sha256', $this->chave, true);
    }

    // ---------- leitura ----------

    /** Listas do ClickUp: [id, nome, caminho]. */
    public function listas(): array
    {
        $listas = [];

        foreach ($this->get('/team')['teams'] ?? [] as $time) {
            foreach ($this->get('/team/' . $time['id'] . '/space?archived=false')['spaces'] ?? [] as $espaco) {
                foreach ($this->get('/space/' . $espaco['id'] . '/folder?archived=false')['folders'] ?? [] as $pasta) {
                    foreach ($pasta['lists'] ?? [] as $l) {
                        $listas[] = ['id' => (string) $l['id'], 'nome' => $l['name'], 'caminho' => $espaco['name'] . ' / ' . $pasta['name'] . ' / ' . $l['name']];
                    }
                }

                foreach ($this->get('/space/' . $espaco['id'] . '/list?archived=false')['lists'] ?? [] as $l) {
                    $listas[] = ['id' => (string) $l['id'], 'nome' => $l['name'], 'caminho' => $espaco['name'] . ' / ' . $l['name']];
                }
            }
        }

        return $listas;
    }

    /** Importa (ou atualiza) os clientes da lista. Opcoes: gestor_id (so admin). */
    public function importar(array $actor, string $listaId, array $opcoes = []): array
    {
        if (!preg_match('/^\d{1,20}$/', $listaId)) {
            throw new InvalidArgumentException('Escolha uma lista válida do ClickUp.');
        }

        $gestorId = $actor['papel'] === 'admin' && !empty($opcoes['gestor_id']) ? (int) $opcoes['gestor_id'] : (int) $actor['id'];
        $r = ['criados' => 0, 'atualizados' => 0, 'notas' => 0];
        $pagina = 0;

        do {
            $resp = $this->get('/list/' . $listaId . '/task?include_closed=false&subtasks=false&page=' . $pagina);

            foreach ($resp['tasks'] ?? [] as $t) {
                $dados = $this->mapear($t);
                $existente = $this->clientes->buscarPorClickup($gestorId, (string) $t['id']);

                if ($existente === null) {
                    $clienteId = $this->clientes->criar($actor, $dados + ['gestor_id' => $gestorId, 'clickup_id' => (string) $t['id']]);
                    $r['criados']++;
                } else {
                    $clienteId = $existente['id'];
                    $this->clientes->atualizar($actor, $clienteId, $dados);
                    $r['atualizados']++;
                }

                foreach ($this->get('/task/' . $t['id'] . '/comment')['comments'] ?? [] as $c) {
                    $texto = trim((string) ($c['comment_text'] ?? ''));

                    if ($texto !== '' && $this->clientes->notaImportada($clienteId, (string) ($c['user']['username'] ?? 'ClickUp'), $texto, 'clickup', (string) $c['id'], date(DATE_ATOM, (int) (((int) ($c['date'] ?? 0)) / 1000)))) {
                        $r['notas']++;
                    }
                }
            }

            $pagina++;
        } while (empty($resp['last_page']) && !empty($resp['tasks']) && $pagina < 50);

        return $r;
    }

    private function mapear(array $t): array
    {
        $status = (string) ($t['status']['status'] ?? '');
        $tags = array_map(static fn (array $x): string => mb_strtolower(trim((string) ($x['name'] ?? ''))), (array) ($t['tags'] ?? []));
        $campos = [];
        $obs = trim((string) ($t['description'] ?? ''));

        foreach ((array) ($t['custom_fields'] ?? []) as $cf) {
            $valor = $cf['value'] ?? null;

            if (is_scalar($valor) && (string) $valor !== '') {
                $campos[] = ['nome' => (string) $cf['name'], 'valor' => (string) $valor];
                $obs .= ($obs === '' ? '' : "\n") . $cf['name'] . ': ' . $valor;
            }
        }

        $sem = static fn (string $s): string => strtr(mb_strtolower($s), ['á' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        $dias = [];
        $turno = 'manha';

        foreach ($tags as $tag) {
            $limpa = $sem($tag);

            foreach (Rotina::DIAS as $dia) {
                if (str_starts_with($limpa, $dia)) {
                    $dias[] = $dia;
                }
            }

            if (in_array($limpa, ['13h', 'tarde'], true)) {
                $turno = 'tarde';
            } elseif (in_array($limpa, ['17h', 'fechamento'], true)) {
                $turno = 'fechamento';
            }
        }

        if ($dias === [] && !empty($t['due_date'])) {
            $n = (int) (new DateTimeImmutable('@' . (int) ((int) $t['due_date'] / 1000)))->setTimezone(new DateTimeZone('America/Fortaleza'))->format('N');

            if ($n <= 5) {
                $dias[] = Rotina::DIAS[$n - 1];
            }
        }

        $limpoStatus = $sem($status);
        $situacao = preg_match('/\bativos?\b/', $limpoStatus) ? 'ativo' : (preg_match('/\bnov[oa]s?\b/', $limpoStatus) ? 'novo' : 'pausado');

        return [
            'nome' => mb_substr(trim((string) $t['name']), 0, 120),
            'descricao' => mb_substr(trim((string) ($t['description'] ?? '')), 0, 500),
            'observacoes' => mb_substr($obs, 0, 2000),
            'dias' => array_values(array_unique($dias)),
            'turno' => $turno,
            'status' => $situacao,
            'link' => preg_match('#^https?://#i', (string) ($t['url'] ?? '')) ? (string) $t['url'] : '',
            'clickup_dados' => json_encode(['url' => (string) ($t['url'] ?? ''), 'status' => $status, 'tags' => $tags, 'responsaveis' => array_column((array) ($t['assignees'] ?? []), 'username'),
                'prazo' => $t['due_date'] ?? null, 'campos' => $campos], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function get(string $caminho): array
    {
        $r = ($this->transporte)(self::BASE . $caminho, $this->token());

        if ($r['status'] === 401 || $r['status'] === 403) {
            throw new RuntimeException('O ClickUp recusou o token. Gere um novo e cole de novo.');
        }

        if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($r['json'])) {
            throw new RuntimeException('Não foi possível ler o ClickUp agora (código ' . $r['status'] . ').');
        }

        return $r['json'];
    }

    /** @return array{status:int,json:?array} */
    private function transporteReal(string $url, string $token): array
    {
        if (!str_starts_with($url, self::BASE . '/')) {
            throw new RuntimeException('Endereço não permitido.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => ['Authorization: ' . $token, 'Accept: application/json']]);
        $corpo = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'json' => is_string($corpo) ? json_decode($corpo, true) : null];
    }
}
