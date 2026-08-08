<?php

declare(strict_types=1);

namespace SKC\FormStudio;

use PDO;

final class Auth
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function login(string $username, string $password): ?array
    {
        if ($this->provider() === 'users') {
            return $this->loginFromUsers($username, $password);
        }

        $statement = $this->db->prepare(
            'SELECT * FROM ' . $this->funcionariosTable() . ' WHERE LOWER(TRIM(user_others_apss)) = LOWER(?) LIMIT 1'
        );
        $statement->execute([trim($username)]);
        $user = $statement->fetch();
        if (!$user || !$this->isActiveFuncionario($user)) {
            return null;
        }

        $storedPassword = (string) ($user['pass_others_apss'] ?? '');
        if (!$this->passwordMatches($password, $storedPassword)) {
            return null;
        }

        return $this->createSession($user, true);
    }

    public function userFromRequest(): ?array
    {
        $token = Http::bearerToken();
        if ($token === '') {
            return null;
        }

        $funcionario = $this->provider() === 'funcionarios';
        $query = $funcionario
            ? 'SELECT f.* FROM skc_app_api_tokens t JOIN ' . $this->funcionariosTable() . ' f ON CAST(f.id_empleado AS UNSIGNED) = t.user_id WHERE t.token_hash = ? AND t.expires_at > ? LIMIT 1'
            : 'SELECT u.* FROM skc_app_api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires_at > ? LIMIT 1';
        $statement = $this->db->prepare($query);
        $statement->execute([$this->tokenHash($token), gmdate('Y-m-d H:i:s')]);
        $user = $statement->fetch();
        if (!$user || $funcionario && !$this->isActiveFuncionario($user)) {
            return null;
        }

        return $this->present($user, $funcionario);
    }

    public function requireUser(): array
    {
        $user = $this->userFromRequest();
        if (!$user) {
            Http::error('unauthorized', 'Inicia sesión para continuar.', 401);
        }
        return $user;
    }

    public function logout(): void
    {
        $token = Http::bearerToken();
        if ($token !== '') {
            $this->db->prepare('DELETE FROM skc_app_api_tokens WHERE token_hash = ?')
                ->execute([$this->tokenHash($token)]);
        }
    }

    private function loginFromUsers(string $email, string $password): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $statement->execute([strtolower(trim($email))]);
        $user = $statement->fetch();
        if (!$user) {
            return null;
        }
        $hash = (string) ($user['password_hash'] ?? $user['password'] ?? '');
        if ($hash === '' || !password_verify($password, $hash) || isset($user['active']) && !(bool) $user['active']) {
            return null;
        }

        return $this->createSession($user, false);
    }

    private function createSession(array $user, bool $funcionario): array
    {
        $userId = (int) ($funcionario ? $user['id_empleado'] : $user['id']);
        $token = bin2hex(random_bytes(32));
        $ttl = max(1, min(168, (int) Env::get('TOKEN_TTL_HOURS', '12')));
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + ($ttl * 3600));
        $this->db->prepare('DELETE FROM skc_app_api_tokens WHERE expires_at < ?')->execute([$now]);
        $this->db->prepare('INSERT INTO skc_app_api_tokens (user_id,token_hash,expires_at,created_at) VALUES (?,?,?,?)')
            ->execute([$userId, $this->tokenHash($token), $expires, $now]);

        return ['token' => $token, 'expiresAt' => $expires, 'user' => $this->present($user, $funcionario)];
    }

    private function present(array $user, bool $funcionario): array
    {
        if ($funcionario) {
            return [
                'id' => (int) $user['id_empleado'],
                'username' => trim((string) ($user['user_others_apss'] ?? '')),
                'name' => trim((string) ($user['nombre'] ?? $user['user_others_apss'] ?? 'Funcionario')),
                'email' => trim((string) ($user['correo'] ?? '')),
                'role' => trim((string) ($user['rol'] ?? '')) ?: 'Funcionario',
            ];
        }

        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }

    private function provider(): string
    {
        $configured = strtolower(trim((string) Env::get('AUTH_PROVIDER', '')));
        if ($configured !== '') {
            return $configured === 'users' ? 'users' : 'funcionarios';
        }

        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'funcionarios' : 'users';
    }

    private function funcionariosTable(): string
    {
        $table = trim((string) Env::get(
            'AUTH_FUNCIONARIOS_TABLE',
            (string) Env::get('LEGACY_TABLE_PREFIX', 'wp_') . 'jet_cct_funcionarios'
        ));
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \RuntimeException('AUTH_FUNCIONARIOS_TABLE contiene un nombre inválido.');
        }

        return '`' . $table . '`';
    }

    private function isActiveFuncionario(array $user): bool
    {
        $active = strtolower(trim((string) ($user['activo'] ?? '')));
        return in_array($active, ['si', 'sí', '1', 'true', 'activo', 'active', 'yes'], true);
    }

    private function passwordMatches(string $provided, string $stored): bool
    {
        if ($provided === '' || $stored === '') {
            return false;
        }
        if (str_starts_with($stored, '$2') || str_starts_with($stored, '$argon2')) {
            return password_verify($provided, $stored);
        }

        // Compatibilidad temporal con pass_others_apss, que actualmente guarda texto legado.
        return hash_equals($stored, $provided);
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', 'v2:' . $this->provider() . ':' . $token);
    }
}
