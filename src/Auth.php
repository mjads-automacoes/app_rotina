<?php

declare(strict_types=1);

final class Auth
{
    public function __construct(private PDO $pdo)
    {
    }

    public function total(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    }

    public function criar(string $nome, string $email, string $senha, string $papel): int
    {
        $nome = trim($nome);
        $email = strtolower(trim($email));

        if ($nome === '' || mb_strlen($nome) > 120) {
            throw new InvalidArgumentException('Informe o nome (até 120 caracteres).');
        }

        // a coluna "email" guarda o LOGIN: pode ser um e-mail ou um login simples (ex.: admin)
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false && !preg_match('/^[a-z0-9][a-z0-9._-]{2,59}$/', $email)) {
            throw new InvalidArgumentException('Informe um login (3 a 60 letras, números, ponto, traço ou sublinhado) ou um e-mail válido.');
        }

        $this->validarSenha($senha);

        if (!in_array($papel, ['admin', 'gestor'], true)) {
            throw new InvalidArgumentException('O papel informado é inválido (use admin ou gestor).');
        }

        $existe = $this->pdo->prepare('SELECT 1 FROM usuarios WHERE email = ?');
        $existe->execute([$email]);

        if ($existe->fetchColumn()) {
            throw new InvalidArgumentException('Esse login já existe.');
        }

        $this->pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, papel, ativo, criado_em) VALUES (?, ?, ?, ?, 1, ?)')
            ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $papel, date(DATE_ATOM)]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Usuario sem o hash. */
    public function buscar(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, nome, email, papel, ativo, criado_em FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch();

        return $u === false ? null : $u;
    }

    public function listar(): array
    {
        return $this->pdo->query('SELECT id, nome, email, papel, ativo, criado_em FROM usuarios ORDER BY papel, nome')->fetchAll();
    }

    public function definirAtivo(int $id, bool $ativo): void
    {
        $this->pdo->prepare('UPDATE usuarios SET ativo = ? WHERE id = ?')->execute([$ativo ? 1 : 0, $id]);
    }

    public function trocarSenha(int $id, string $nova): void
    {
        $this->validarSenha($nova);
        $this->pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $id]);
    }

    public function bloqueado(string $email, string $ip): bool
    {
        $desde = time() - (int) rotina_config('janela_bloqueio_min') * 60;
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM tentativas_login WHERE email = ? AND ip = ? AND criado_em >= ?');
        $st->execute([strtolower(trim($email)), $ip, $desde]);

        return (int) $st->fetchColumn() >= (int) rotina_config('max_tentativas_login');
    }

    /** Devolve o usuario (sem hash) ou null. Erros contam para o bloqueio; acerto zera. */
    public function autenticar(string $email, string $senha, string $ip): ?array
    {
        $email = strtolower(trim($email));

        if ($this->bloqueado($email, $ip)) {
            return null;
        }

        $st = $this->pdo->prepare('SELECT id, senha_hash, ativo FROM usuarios WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();
        // Compara sempre contra algum hash para nao revelar (pelo tempo) se o e-mail existe.
        $hash = $u !== false ? (string) $u['senha_hash'] : '$2y$10$usesomesillystringforsalt.0000000000000000000000000000000';
        $certa = password_verify($senha, $hash);

        if ($u === false || !$certa || (int) $u['ativo'] !== 1) {
            $this->pdo->prepare('INSERT INTO tentativas_login (email, ip, criado_em) VALUES (?, ?, ?)')->execute([$email, $ip, time()]);

            return null;
        }

        $this->pdo->prepare('DELETE FROM tentativas_login WHERE email = ? AND ip = ?')->execute([$email, $ip]);

        if (password_needs_rehash((string) $u['senha_hash'], PASSWORD_DEFAULT)) {
            $this->pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
        }

        return $this->buscar((int) $u['id']);
    }

    private function validarSenha(string $senha): void
    {
        $min = (int) rotina_config('senha_min');

        if (mb_strlen($senha) < $min) {
            throw new InvalidArgumentException($min > 1 ? 'A senha precisa ter pelo menos ' . $min . ' caracteres.' : 'Informe a senha.');
        }
    }
}
