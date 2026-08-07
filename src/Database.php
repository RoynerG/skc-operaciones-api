<?php

declare(strict_types=1);

namespace SKC\FormStudio;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $driver = Env::get('DB_DRIVER', 'sqlite');
        if ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::get('DB_PORT', '3306'),
                Env::get('DB_DATABASE', 'form_studio')
            );
            self::$connection = new PDO($dsn, Env::get('DB_USERNAME', 'root'), Env::get('DB_PASSWORD', ''));
        } else {
            $database = Env::get('DB_DATABASE', 'storage/form-studio.sqlite') ?? 'storage/form-studio.sqlite';
            if (!preg_match('/^[A-Za-z]:[\\\\\/]/', $database) && !str_starts_with($database, '/')) {
                $database = FORM_STUDIO_ROOT . '/' . $database;
            }
            $directory = dirname($database);
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            self::$connection = new PDO('sqlite:' . $database);
            self::$connection->exec('PRAGMA foreign_keys = ON');
        }

        self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::migrate(self::$connection, $driver === 'mysql');
        return self::$connection;
    }

    public static function disconnect(): void
    {
        self::$connection = null;
    }

    private static function migrate(PDO $db, bool $mysql): void
    {
        if ($mysql) {
            // La base productiva ya posee `users` y el dominio del negocio.
            // La app solo agrega infraestructura propia, sin alterar esas tablas.
            $db->exec("CREATE TABLE IF NOT EXISTS skc_app_api_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash VARCHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX token_user (user_id), INDEX token_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS skc_app_rate_limits (
                key_hash VARCHAR(64) PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 1,
                expires_at DATETIME NOT NULL, INDEX rate_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS skc_app_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_name VARCHAR(120) NOT NULL,
                aggregate_type VARCHAR(80) NOT NULL,
                aggregate_id VARCHAR(80) NULL,
                payload_json LONGTEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL,
                processed_at DATETIME NULL,
                INDEX event_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $reference = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $json = $mysql ? 'LONGTEXT' : 'TEXT';
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id {$id}, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL, role VARCHAR(32) NOT NULL DEFAULT 'admin',
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS skc_app_api_tokens (
            id {$id}, user_id {$reference} NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS forms (
            id {$id}, slug VARCHAR(100) NOT NULL UNIQUE, title VARCHAR(190) NOT NULL,
            description TEXT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'draft',
            version INTEGER NOT NULL DEFAULT 1, definition_json {$json} NOT NULL,
            created_by {$reference} NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS drafts (
            id {$id}, form_id {$reference} NOT NULL, draft_key VARCHAR(100) NOT NULL,
            client_id VARCHAR(128) NOT NULL, revision INTEGER NOT NULL DEFAULT 1,
            payload_json {$json} NOT NULL, expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE (form_id, draft_key, client_id),
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS submissions (
            id {$id}, form_id {$reference} NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'completed',
            payload_json {$json} NOT NULL, metadata_json {$json} NOT NULL,
            action_result_json {$json} NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS skc_app_rate_limits (
            key_hash VARCHAR(64) PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 1,
            expires_at DATETIME NOT NULL
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS skc_app_events (
            id {$id}, event_name VARCHAR(120) NOT NULL, aggregate_type VARCHAR(80) NOT NULL,
            aggregate_id VARCHAR(80), payload_json {$json} NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL, processed_at DATETIME
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS wp_skc_inventory_app_drafts (
            id {$id}, user_id {$reference} NOT NULL, draft_key VARCHAR(64) NOT NULL,
            mode VARCHAR(16) NOT NULL, inventory_id {$reference}, revision INTEGER NOT NULL DEFAULT 1,
            payload {$json} NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL, UNIQUE(user_id, draft_key)
        )");

        self::seed($db);
    }

    private static function seed(PDO $db): void
    {
        $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            $password = Env::get('ADMIN_PASSWORD', 'ChangeMe123!') ?? 'ChangeMe123!';
            if (Env::get('APP_ENV', 'production') === 'production' && $password === 'ChangeMe123!') {
                throw new \RuntimeException('Define una contraseña ADMIN_PASSWORD segura antes de iniciar en producción.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $statement = $db->prepare('INSERT INTO users (name,email,password_hash,role,created_at,updated_at) VALUES (?,?,?,?,?,?)');
            $statement->execute([
                Env::get('ADMIN_NAME', 'Administrador'),
                strtolower(Env::get('ADMIN_EMAIL', 'admin@skc.local') ?? 'admin@skc.local'),
                password_hash($password, PASSWORD_DEFAULT),
                'admin', $now, $now,
            ]);
        }

        $formCount = (int) $db->query('SELECT COUNT(*) FROM forms')->fetchColumn();
        if ($formCount !== 0) {
            return;
        }
        $definition = FormRepository::starter('inventario-general', 'Inventario general');
        $definition['description'] = 'Plantilla inicial para demostrar el motor de formularios.';
        $definition['status'] = 'active';
        $definition['sections'] = [
            [
                'id' => 'datos-generales', 'title' => 'Datos generales', 'description' => 'Identificación del inmueble y responsable.',
                'fields' => [
                    ['id' => 'inmueble', 'type' => 'text', 'name' => 'inmueble', 'label' => 'Inmueble', 'description' => '', 'placeholder' => 'Dirección o código', 'required' => true, 'options' => []],
                    ['id' => 'responsable', 'type' => 'text', 'name' => 'responsable', 'label' => 'Responsable', 'description' => '', 'placeholder' => 'Nombre completo', 'required' => true, 'options' => []],
                    ['id' => 'fecha', 'type' => 'date', 'name' => 'fecha', 'label' => 'Fecha', 'description' => '', 'placeholder' => '', 'required' => true, 'options' => []],
                ],
            ],
            [
                'id' => 'estado', 'title' => 'Estado del inmueble', 'description' => 'Registra los hallazgos principales.',
                'fields' => [
                    ['id' => 'estado_general', 'type' => 'select', 'name' => 'estado_general', 'label' => 'Estado general', 'description' => '', 'placeholder' => '', 'required' => true, 'options' => [['label' => 'Bueno', 'value' => 'bueno'], ['label' => 'Regular', 'value' => 'regular'], ['label' => 'Requiere atención', 'value' => 'requiere-atencion']]],
                    ['id' => 'observaciones', 'type' => 'textarea', 'name' => 'observaciones', 'label' => 'Observaciones', 'description' => 'Incluye daños, faltantes o recomendaciones.', 'placeholder' => 'Describe lo encontrado', 'required' => false, 'options' => []],
                ],
            ],
        ];
        $now = gmdate('Y-m-d H:i:s');
        $statement = $db->prepare('INSERT INTO forms (slug,title,description,status,version,definition_json,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
        $statement->execute([$definition['slug'], $definition['title'], $definition['description'], 'active', 1, json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, $now, $now]);
    }
}
