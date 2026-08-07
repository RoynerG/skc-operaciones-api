<?php

declare(strict_types=1);

use SKC\FormStudio\App;
use SKC\FormStudio\Env;

define('FORM_STUDIO_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'SKC\\FormStudio\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = FORM_STUDIO_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

Env::load(FORM_STUDIO_ROOT . '/.env');

try {
    (new App())->run();
} catch (Throwable $error) {
    error_log('[Form Studio] ' . $error->getMessage() . "\n" . $error->getTraceAsString());
    $debug = Env::get('APP_ENV', 'production') === 'development';
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'server_error',
        'message' => $debug ? $error->getMessage() : 'Ocurrió un error interno.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
