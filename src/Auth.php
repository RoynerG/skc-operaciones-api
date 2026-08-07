<?php

declare(strict_types=1);

namespace SKC\FormStudio;

use PDO;

final class Auth
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function login(string $email, string $password): ?array
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

        $token = bin2hex(random_bytes(32));
        $ttl = max(1, min(168, (int) Env::get('TOKEN_TTL_HOURS', '12')));
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + ($ttl * 3600));
        $this->db->prepare('DELETE FROM skc_app_api_tokens WHERE expires_at < ?')->execute([$now]);
        $this->db->prepare('INSERT INTO skc_app_api_tokens (user_id,token_hash,expires_at,created_at) VALUES (?,?,?,?)')
            ->execute([$user['id'], hash('sha256', $token), $expires, $now]);

        return ['token' => $token, 'expiresAt' => $expires, 'user' => $this->present($user)];
    }

    public function userFromRequest(): ?array
    {
        $token = Http::bearerToken();
        if ($token === '') {
            return null;
        }
        $statement = $this->db->prepare(
            'SELECT u.* FROM skc_app_api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires_at > ? LIMIT 1'
        );
        $statement->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s')]);
        $user = $statement->fetch();
        return $user ? $this->present($user) : null;
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
            $this->db->prepare('DELETE FROM skc_app_api_tokens WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        }
    }

    private function present(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }
}
