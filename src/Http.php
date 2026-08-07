<?php

declare(strict_types=1);

namespace SKC\FormStudio;

final class Http
{
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::error('invalid_json', 'El cuerpo de la solicitud no contiene JSON válido.', 400);
        }
        return $data;
    }

    public static function bearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        // Compatibilidad temporal con el runtime migrado del formulario de inventario.
        return trim((string) ($_SERVER['HTTP_X_WP_NONCE'] ?? ''));
    }

    public static function respond(array $data = [], int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $code, string $message, int $status = 400, array $details = []): never
    {
        self::respond(['error' => $code, 'message' => $message, 'details' => $details], $status);
    }

    public static function cors(): void
    {
        $allowed = array_filter(array_map('trim', explode(',', Env::get('FRONTEND_URL', 'http://localhost:5173') ?? '')));
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Form-Client, X-WP-Nonce');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 600');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
