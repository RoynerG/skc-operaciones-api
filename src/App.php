<?php

declare(strict_types=1);

namespace SKC\FormStudio;

use InvalidArgumentException;
use PDO;

final class App
{
    private Auth $auth;
    private FormRepository $forms;
    private ActionRunner $actions;
    private InventoryModule $inventory;
    private Branding $branding;
    private PDO $db;

    public function __construct()
    {
        $appKey = Env::get('APP_KEY', '') ?? '';
        if (Env::get('APP_ENV', 'production') === 'production' && strlen($appKey) < 32) {
            throw new \RuntimeException('Define un APP_KEY aleatorio de al menos 32 caracteres antes de iniciar en producción.');
        }
        $db = Database::connection();
        $this->db = $db;
        $this->auth = new Auth($db);
        $this->forms = new FormRepository($db);
        $this->actions = new ActionRunner();
        $this->inventory = new InventoryModule($db);
        $this->branding = new Branding($db);
    }

    public function run(): never
    {
        Http::cors();
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

        try {
            if ($method === 'GET' && $path === '/api/health') {
                Http::respond(['status' => 'ok', 'service' => 'SKC Operaciones API', 'time' => gmdate(DATE_ATOM)]);
            }
            if ($method === 'GET' && $path === '/api/branding') {
                Http::respond($this->branding->publicConfig());
            }
            if ($method === 'POST' && $path === '/api/auth/login') {
                $body = Http::jsonBody();
                $username = (string) ($body['username'] ?? $body['email'] ?? '');
                $session = $this->auth->login($username, (string) ($body['password'] ?? ''));
                $session ? Http::respond($session) : Http::error('invalid_credentials', 'Usuario o contraseña incorrectos.', 401);
            }
            if ($method === 'GET' && $path === '/api/auth/me') {
                Http::respond(['user' => $this->auth->requireUser()]);
            }
            if ($method === 'POST' && $path === '/api/auth/logout') {
                $this->auth->requireUser();
                $this->auth->logout();
                Http::respond(['ok' => true]);
            }
            if ($method === 'GET' && $path === '/api/modules') {
                $this->auth->requireUser();
                Http::respond(['modules' => [$this->inventory->descriptor()]]);
            }
            if ($method === 'GET' && $path === '/api/modules/inventory/recent') {
                $this->auth->requireUser();
                Http::respond(['records' => $this->inventory->recent((int) ($_GET['limit'] ?? 12))]);
            }
            if ($method === 'GET' && $path === '/api/modules/inventory/bootstrap') {
                $user = $this->auth->requireUser();
                Http::respond($this->inventory->bootstrap(
                    (string) ($_GET['mode'] ?? 'add'),
                    $this->inventoryContext($_GET['context'] ?? []),
                    $user
                ));
            }
            if ($method === 'GET' && preg_match('#^/api/modules/inventory/glossary/(\d+)$#', $path, $matches)) {
                $this->auth->requireUser();
                Http::respond(['options' => $this->inventory->glossary((int) $matches[1])]);
            }
            if ($path === '/api/modules/inventory/draft' && in_array($method, ['POST', 'DELETE'], true)) {
                $user = $this->auth->requireUser();
                $body = Http::jsonBody();
                $mode = (string) ($body['mode'] ?? 'add');
                $context = $this->inventoryContext($body['context'] ?? []);
                if ($method === 'POST') Http::respond($this->inventory->saveDraft($mode, $context, $body, $user));
                Http::respond(['deleted' => $this->inventory->deleteDraft($mode, $context, $user)]);
            }
            if ($method === 'POST' && $path === '/api/modules/inventory/submit') {
                $this->throttle('inventory-submit', 30, 3600);
                $user = $this->auth->requireUser();
                $body = Http::jsonBody();
                Http::respond($this->inventory->submit(
                    (string) ($body['mode'] ?? 'add'),
                    $this->inventoryContext($body['context'] ?? []),
                    (array) ($body['values'] ?? []),
                    $user
                ), 201);
            }
            if ($method === 'POST' && $path === '/api/modules/inventory/ai/fill') {
                $this->throttle('inventory-ai', 10, 60);
                $this->auth->requireUser();
                $body = Http::jsonBody();
                try {
                    Http::respond($this->inventory->aiFill(
                        (string) ($body['mode'] ?? 'add'),
                        (string) ($body['sectionId'] ?? ''),
                        (string) ($body['transcript'] ?? '')
                    ));
                } catch (\RuntimeException $error) {
                    error_log('[Form Studio AI] ' . $error->getMessage());
                    $safeMessage = preg_match('/^(MiniMax|No se pudo conectar con MiniMax|El endpoint|Endpoint externo|La extensión cURL)/u', $error->getMessage())
                        ? $error->getMessage()
                        : 'No fue posible procesar el dictado con MiniMax. Revisa la configuración del proveedor.';
                    Http::error('ai_provider_error', $safeMessage, 502);
                }
            }
            if ($method === 'GET' && $path === '/api/functions') {
                $this->auth->requireUser();
                Http::respond(['functions' => $this->actions->availableFunctions()]);
            }
            if ($path === '/api/forms') {
                $user = $this->auth->requireUser();
                if ($method === 'GET') Http::respond(['forms' => $this->forms->all()]);
                if ($method === 'POST') Http::respond(['form' => $this->forms->create(Http::jsonBody(), $user['id'])], 201);
            }

            if (preg_match('#^/api/forms/([a-z0-9-]+)/submissions$#', $path, $matches)) {
                $this->auth->requireUser();
                $form = $this->requireForm($matches[1]);
                if ($method === 'GET') Http::respond(['submissions' => $this->forms->submissions($form)]);
            }
            if (preg_match('#^/api/forms/([a-z0-9-]+)$#', $path, $matches)) {
                $this->auth->requireUser();
                $form = $this->requireForm($matches[1]);
                if ($method === 'GET') Http::respond(['form' => $form]);
                if ($method === 'PUT' || $method === 'PATCH') Http::respond(['form' => $this->forms->update($matches[1], Http::jsonBody())]);
                if ($method === 'DELETE') Http::respond(['deleted' => $this->forms->delete($matches[1])]);
            }

            if (preg_match('#^/api/public/forms/([a-z0-9-]+)$#', $path, $matches) && $method === 'GET') {
                $form = $this->forms->publicDefinition($matches[1]);
                $form ? Http::respond(['form' => $form]) : Http::error('form_not_found', 'El formulario no existe o no está publicado.', 404);
            }
            if (preg_match('#^/api/public/forms/([a-z0-9-]+)/drafts/([a-zA-Z0-9_-]+)$#', $path, $matches)) {
                $form = $this->requirePublicForm($matches[1]);
                $client = $_SERVER['HTTP_X_FORM_CLIENT'] ?? '';
                if ($method === 'GET') Http::respond(['draft' => $this->forms->getDraft($form, $matches[2], $client)]);
                if ($method === 'PUT' || $method === 'PATCH') {
                    $this->throttle('draft:' . $matches[1], 240, 3600);
                    $body = Http::jsonBody();
                    Http::respond($this->forms->saveDraft($form, $matches[2], $client, (array) ($body['values'] ?? []), (int) ($body['revision'] ?? 0)));
                }
                if ($method === 'DELETE') Http::respond(['deleted' => $this->forms->deleteDraft($form, $matches[2], $client)]);
            }
            if (preg_match('#^/api/public/forms/([a-z0-9-]+)/submissions$#', $path, $matches) && $method === 'POST') {
                $this->throttle('submit:' . $matches[1], 30, 3600);
                $form = $this->requirePublicForm($matches[1]);
                $body = Http::jsonBody();
                $submission = $this->forms->createSubmission($form, (array) ($body['values'] ?? []), [
                    'ipHash' => hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', Env::get('APP_KEY', 'local-dev-key') ?? 'local-dev-key'),
                    'userAgent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                    'referer' => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1000),
                ]);
                $results = $this->actions->run($form, $submission);
                $this->forms->updateActionResult($submission['id'], $results);
                if (!empty($body['draftKey']) && !empty($_SERVER['HTTP_X_FORM_CLIENT'])) {
                    try { $this->forms->deleteDraft($form, (string) $body['draftKey'], (string) $_SERVER['HTTP_X_FORM_CLIENT']); } catch (\Throwable) {}
                }
                Http::respond([
                    'submissionId' => $submission['id'], 'message' => $form['successMessage'],
                    'redirectUrl' => $form['redirectUrl'], 'actions' => $results,
                ], 201);
            }
        } catch (InvalidArgumentException $error) {
            $decoded = json_decode($error->getMessage(), true);
            if (is_array($decoded) && !empty($decoded['conflict'])) {
                Http::error('draft_conflict', 'Hay una versión más reciente del borrador en el servidor.', 409, $decoded);
            }
            if (is_array($decoded) && isset($decoded['validation'])) {
                Http::error('validation_failed', 'Revisa los campos señalados.', 422, $decoded);
            }
            Http::error('invalid_request', $error->getMessage(), str_contains($error->getMessage(), 'versión') ? 409 : 422);
        }

        Http::error('route_not_found', 'La ruta solicitada no existe.', 404);
    }

    private function inventoryContext(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    private function requireForm(string $slug): array
    {
        $form = $this->forms->find($slug);
        return $form ?: Http::error('form_not_found', 'No se encontró el formulario.', 404);
    }

    private function requirePublicForm(string $slug): array
    {
        $form = $this->forms->find($slug, true);
        return $form ?: Http::error('form_not_found', 'El formulario no existe o no está publicado.', 404);
    }

    private function throttle(string $scope, int $maximum, int $windowSeconds): void
    {
        $key = hash_hmac(
            'sha256',
            $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            Env::get('APP_KEY', 'local-development-key') ?? 'local-development-key'
        );
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare('SELECT hits,expires_at FROM skc_app_rate_limits WHERE key_hash=? LIMIT 1');
        $statement->execute([$key]);
        $rate = $statement->fetch();
        if (!$rate || $rate['expires_at'] <= $now) {
            $this->db->prepare('DELETE FROM skc_app_rate_limits WHERE key_hash=?')->execute([$key]);
            $this->db->prepare('INSERT INTO skc_app_rate_limits (key_hash,hits,expires_at) VALUES (?,?,?)')
                ->execute([$key, 1, gmdate('Y-m-d H:i:s', time() + $windowSeconds)]);
            return;
        }
        if ((int) $rate['hits'] >= $maximum) {
            Http::error('rate_limit', 'Se alcanzó el límite temporal de solicitudes. Intenta más tarde.', 429);
        }
        $this->db->prepare('UPDATE skc_app_rate_limits SET hits=hits+1 WHERE key_hash=?')->execute([$key]);
    }
}
