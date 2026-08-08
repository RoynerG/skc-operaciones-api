<?php

declare(strict_types=1);

namespace SKC\FormStudio;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class InventoryModule
{
    private const MODES = ['add', 'edit', 'send'];
    private const MINIMAX_CHAT_ENDPOINT = 'https://api.minimax.io/v1/chat/completions';
    private const CONTEXT_KEYS = [
        'id_inventario', 'id_formulario', 'id_inmueble', 'id_contrato', 'id_revision',
        'id_ticket', 'id_propietario', 'id_arrendatario', 'id_sucursal', 'id_confi',
        'id_estudio_aseguradora', 'numero_solicitud',
    ];

    private array $schema;
    private array $privateConfig;
    private array $columns = [];
    private array $tableCache = [];

    public function __construct(private readonly PDO $db)
    {
        $schemaPath = trim((string) Env::get('INVENTORY_SCHEMA_PATH', '')) ?: FORM_STUDIO_ROOT . '/config/inventory/forms.json';
        $decoded = json_decode((string) file_get_contents((string) $schemaPath), true);
        if (!is_array($decoded) || !isset($decoded['modes'])) {
            throw new RuntimeException('La definición nativa del inventario no es válida.');
        }
        $this->schema = $decoded;

        $actionsPath = trim((string) Env::get('INVENTORY_ACTIONS_PATH', '')) ?: FORM_STUDIO_ROOT . '/config/inventory/private-actions.php';
        if (!defined('ABSPATH')) {
            define('ABSPATH', FORM_STUDIO_ROOT . '/');
        }
        $private = is_file((string) $actionsPath) ? require (string) $actionsPath : [];
        $this->privateConfig = is_array($private) ? $private : [];
    }

    public function descriptor(): array
    {
        $total = 0;
        if ($this->tableExists($this->table('inventario'))) {
            $total = (int) $this->db->query('SELECT COUNT(*) FROM ' . $this->identifier($this->table('inventario')))->fetchColumn();
        }
        return [
            'slug' => 'inventory',
            'title' => 'Inventario inmobiliario',
            'description' => 'Captura, edición y envío de inventarios sobre la base operativa actual.',
            'status' => 'active',
            'records' => $total,
            'modes' => self::MODES,
            'capabilities' => ['autosave', 'voice', 'ai', 'repeaters', 'conditions', 'shared-database'],
        ];
    }

    public function recent(int $limit = 12): array
    {
        $table = $this->table('inventario');
        if (!$this->tableExists($table)) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $columns = $this->tableColumns($table);
        $select = array_values(array_intersect(
            ['_ID', 'direccion', 'inmueble', 'tipo_inventario', 'categoria_inventario', 'se_envio', 'cct_modified'],
            array_keys($columns)
        ));
        $sql = 'SELECT ' . implode(',', array_map([$this, 'identifier'], $select))
            . ' FROM ' . $this->identifier($table) . ' ORDER BY _ID DESC LIMIT ' . $limit;
        $rows = $this->db->query($sql)->fetchAll();
        return array_map(static fn (array $row): array => [
            'id' => (int) ($row['_ID'] ?? 0),
            'property' => (string) ($row['direccion'] ?? $row['inmueble'] ?? 'Inventario'),
            'type' => (string) ($row['tipo_inventario'] ?? $row['categoria_inventario'] ?? ''),
            'sent' => (string) ($row['se_envio'] ?? ''),
            'updatedAt' => (string) ($row['cct_modified'] ?? ''),
        ], $rows);
    }

    public function bootstrap(string $mode, array $context, array $user): array
    {
        $mode = $this->mode($mode);
        $context = $this->context($context);
        $form = $this->form($mode);
        $values = $this->defaultValues($form, $context, $user);
        $inventoryId = (int) ($context['id_inventario'] ?? 0);
        if ($inventoryId > 0) {
            $values = array_replace($values, $this->inventoryValues($mode, $inventoryId));
        }

        $draftKey = $this->draftKey((int) $user['id'], $mode, $context);
        $draft = $this->draft((int) $user['id'], $draftKey);
        if (!empty($draft['values'])) {
            $values = array_replace($values, $draft['values']);
        }

        $public = $form;
        unset($public['actions'], $public['inventoryMap']);
        return [
            'schema' => $public,
            'values' => $values,
            'context' => $context,
            'draftKey' => $draftKey,
            'revision' => (int) ($draft['revision'] ?? 0),
            'draftUpdatedAt' => (string) ($draft['updatedAt'] ?? ''),
            'inventoryId' => $inventoryId,
        ];
    }

    public function glossary(int $id): array
    {
        $table = $this->prefix() . 'jet_post_types';
        if ($id < 1 || !$this->tableExists($table)) {
            return [];
        }
        $statement = $this->db->prepare('SELECT meta_fields FROM ' . $this->identifier($table) . " WHERE id=? AND status='glossary' LIMIT 1");
        $statement->execute([$id]);
        $items = $this->decodeValue($statement->fetchColumn());
        if (!is_array($items)) {
            return [];
        }
        $options = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = (string) ($item['value'] ?? '');
            $options[] = ['value' => $value, 'label' => (string) ($item['label'] ?? $value)];
        }
        return $options;
    }

    public function saveDraft(string $mode, array $context, array $body, array $user): array
    {
        $mode = $this->mode($mode);
        $context = $this->context($context);
        $expected = $this->draftKey((int) $user['id'], $mode, $context);
        if (!hash_equals($expected, (string) ($body['draftKey'] ?? ''))) {
            throw new InvalidArgumentException('La clave del borrador no coincide.');
        }
        $existing = $this->draft((int) $user['id'], $expected);
        $currentRevision = (int) ($existing['revision'] ?? 0);
        $clientRevision = (int) ($body['revision'] ?? 0);
        if ($clientRevision > 0 && $currentRevision > $clientRevision) {
            throw new InvalidArgumentException(json_encode(['validation' => [], 'draft' => $existing, 'conflict' => true]));
        }
        $values = (array) ($existing['values'] ?? []);
        foreach ($this->filterValues($mode, (array) ($body['patch'] ?? [])) as $name => $value) {
            $values[$name] = $value;
        }
        $now = gmdate('Y-m-d H:i:s');
        $revision = $currentRevision + 1;
        $payload = $this->json($values);
        $table = $this->draftTable();
        if ($currentRevision > 0) {
            $statement = $this->db->prepare('UPDATE ' . $this->identifier($table) . ' SET mode=?,inventory_id=?,revision=?,payload=?,updated_at=?,expires_at=? WHERE user_id=? AND draft_key=?');
            $statement->execute([$mode, $context['id_inventario'] ?? null, $revision, $payload, $now, gmdate('Y-m-d H:i:s', time() + 1296000), $user['id'], $expected]);
        } else {
            $statement = $this->db->prepare('INSERT INTO ' . $this->identifier($table) . ' (user_id,draft_key,mode,inventory_id,revision,payload,created_at,updated_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?)');
            $statement->execute([$user['id'], $expected, $mode, $context['id_inventario'] ?? null, $revision, $payload, $now, $now, gmdate('Y-m-d H:i:s', time() + 1296000)]);
        }
        return ['revision' => $revision, 'updatedAt' => $now];
    }

    public function deleteDraft(string $mode, array $context, array $user): bool
    {
        $key = $this->draftKey((int) $user['id'], $this->mode($mode), $this->context($context));
        $statement = $this->db->prepare('DELETE FROM ' . $this->identifier($this->draftTable()) . ' WHERE user_id=? AND draft_key=?');
        $statement->execute([$user['id'], $key]);
        return true;
    }

    public function submit(string $mode, array $context, array $rawValues, array $user): array
    {
        $mode = $this->mode($mode);
        $context = $this->context($context);
        $values = array_replace($context, $this->filterValues($mode, $rawValues));
        if ($mode !== 'add' && (int) ($values['id_inventario'] ?? 0) < 1) {
            throw new InvalidArgumentException('Editar o enviar requiere un id_inventario existente.');
        }
        $private = (array) ($this->privateConfig['modes'][$mode] ?? []);
        $actions = (array) ($private['actions'] ?? []);
        $inserted = [];
        $external = [];

        $this->db->beginTransaction();
        try {
            foreach ($actions as $action) {
                if (!$this->shouldRun((array) $action, $values)) {
                    continue;
                }
                $type = (string) ($action['type'] ?? '');
                if ($type === 'insert_custom_content_type') {
                    $this->runCct((array) $action, $values, $inserted, $user);
                } elseif ($type === 'insert_post') {
                    $this->runPost((array) $action, $values, $inserted);
                } elseif ($type === 'call_webhook' || $type === 'call_hook') {
                    $external[] = (array) $action;
                }
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new RuntimeException('No se pudo guardar el inventario: ' . $error->getMessage(), 0, $error);
        }

        $inventoryId = (int) ($inserted['inventario'] ?? $values['id_inventario'] ?? 0);
        foreach ($external as $action) {
            $this->dispatchExternal($action, $mode, $inventoryId, $values, $inserted, $user);
        }
        $this->deleteDraft($mode, $context, $user);
        return [
            'inventoryId' => $inventoryId,
            'insertedIds' => $inserted,
            'message' => (string) ($this->form($mode)['successMessage'] ?? 'Inventario guardado'),
        ];
    }

    public function aiFill(string $mode, string $sectionId, string $transcript): array
    {
        $endpoint = $this->minimaxEndpoint();
        $key = trim((string) Env::get('MINIMAX_API_KEY', ''), " \n\r\t\v\0\"'");
        if ($key === '') {
            throw new InvalidArgumentException('La ayuda con IA todavía no está configurada.');
        }
        $section = null;
        foreach ((array) ($this->form($this->mode($mode))['sections'] ?? []) as $candidate) {
            if (($candidate['id'] ?? '') === $sectionId) {
                $section = $candidate;
                break;
            }
        }
        if (!is_array($section) || trim($transcript) === '') {
            throw new InvalidArgumentException('No se recibió una sección y descripción válidas.');
        }
        $mapper = new InventoryAiMapper();
        $allowed = $mapper->specification($section, fn(int $id): array => $this->glossary($id));
        $prompt = "Convierte el dictado de un inventario inmobiliario en datos estructurados para completar el formulario actual. "
            . "Usa exclusivamente las claves y opciones permitidas. Cada elemento físico mencionado debe ser un objeto dentro del array del repetidor correspondiente. "
            . "Si se menciona un elemento singular sin cantidad, usa cantidad 1. No inventes información ausente. "
            . "Relaciona palabras en singular o variaciones naturales con la opción permitida equivalente; por ejemplo, puerta con Puertas y cerradura con Cerraduras. "
            . "Separa estrictamente elemento, cantidad, material, estado y observaciones según la guía de cada campo. "
            . "La cantidad debe ser un número o la opción Todo el inmueble, nunca ambas. Usa Ninguna únicamente cuando el dictado diga que no existe ningún elemento. "
            . "Responde únicamente JSON válido con la forma {\"values\":{...}}.\nEsquema permitido: "
            . $this->json($allowed) . "\nDictado del funcionario: " . mb_substr(trim($transcript), 0, 12000);
        $response = $this->postJson($endpoint, [
            'model' => Env::get('MINIMAX_MODEL', 'MiniMax-M2.7'),
            'temperature' => 0.2,
            'max_completion_tokens' => max(300, min(2048, (int) Env::get('MINIMAX_MAX_COMPLETION_TOKENS', '1200'))),
            'reasoning_split' => true,
            'messages' => [
                ['role' => 'system', 'content' => 'Eres un asistente preciso de captura inmobiliaria en Colombia. Devuelves únicamente JSON válido, sin explicación.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ], ['Authorization: Bearer ' . $key]);
        $suggested = $mapper->normalize($mapper->decodeResponse($response), $allowed);
        if ($suggested === []) {
            throw new RuntimeException('MiniMax no identificó campos aplicables en esta sección. Describe el elemento, material, estado y observaciones.');
        }
        return ['values' => $suggested];
    }

    private function form(string $mode): array
    {
        $public = (array) ($this->schema['modes'][$mode] ?? []);
        $private = (array) ($this->privateConfig['modes'][$mode] ?? []);
        return array_merge($public, $private);
    }

    private function mode(string $mode): string
    {
        return in_array($mode, self::MODES, true) ? $mode : 'add';
    }

    private function context(array $input): array
    {
        $context = [];
        foreach (self::CONTEXT_KEYS as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $context[$key] = mb_substr(trim((string) $input[$key]), 0, 255);
            }
        }
        return $context;
    }

    private function defaultValues(array $form, array $context, array $user): array
    {
        $values = [];
        $lookup = [];
        foreach ($this->items($form) as $item) {
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (($item['kind'] ?? '') === 'repeater') {
                $values[$name] = [];
                continue;
            }
            if (($item['defaultValue'] ?? '') !== '') {
                $values[$name] = $item['defaultValue'];
            }
            if (array_key_exists($name, $context)) {
                $values[$name] = $context[$name];
                continue;
            }
            $source = (string) ($item['valueSource'] ?? '');
            $queryKey = (string) ($item['queryVarKey'] ?? $name);
            if ($source === 'query_var' && isset($context[$queryKey])) $values[$name] = $context[$queryKey];
            if ($source === 'user_id') $values[$name] = $user['id'];
            if ($source === 'user_name') $values[$name] = $user['name'];
            if ($source === 'user_email') $values[$name] = $user['email'];
            if ($source === 'current_date') $values[$name] = gmdate('Y-m-d');

            $preset = (array) ($item['preset'] ?? []);
            if (($preset['from'] ?? '') === 'custom_content_type') {
                $recordId = (int) ($context[(string) ($preset['query_var'] ?? '')] ?? 0);
                $property = explode('::', (string) ($preset['current_field_prop'] ?? ''), 2);
                if ($recordId > 0 && count($property) === 2) {
                    $values[$name] = $this->normalizeFieldValue($this->lookupCct($property[0], $recordId, $property[1], $lookup), (string) ($item['type'] ?? ''));
                }
            }
            if (!empty($item['updateSourceField'])) {
                $recordId = (int) ($context[(string) $item['updateSourceField']] ?? 0);
                $candidate = $recordId > 0 ? $this->lookupCct('inmuebles', $recordId, $name, $lookup) : '';
                if ($candidate !== '') $values[$name] = $this->normalizeFieldValue($candidate, (string) ($item['type'] ?? ''));
            }
        }
        return $values;
    }

    private function inventoryValues(string $mode, int $id): array
    {
        $table = $this->table('inventario');
        if (!$this->tableExists($table)) return [];
        $statement = $this->db->prepare('SELECT * FROM ' . $this->identifier($table) . ' WHERE _ID=? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch() ?: [];
        $values = [];
        $types = [];
        foreach ($this->items($this->form($mode)) as $item) {
            if (!empty($item['name'])) $types[$item['name']] = (string) ($item['type'] ?? '');
        }
        foreach ((array) ($this->privateConfig['modes'][$mode]['inventoryMap'] ?? []) as $source => $column) {
            if ($column === '_ID') $values[$source] = $id;
            elseif ($column !== '' && array_key_exists($column, $row)) $values[$source] = $this->normalizeFieldValue($this->decodeValue($row[$column]), $types[$source] ?? '');
        }
        $values['id_inventario'] = $id;
        return $values;
    }

    private function lookupCct(string $type, int $id, string $column, array &$cache): mixed
    {
        if (!preg_match('/^[a-z0-9_]+$/', $type) || !preg_match('/^[a-z0-9_]+$/', $column)) return '';
        $table = $this->table($type);
        if (!isset($this->tableColumns($table)[$column])) return '';
        $key = $table . ':' . $id;
        if (!isset($cache[$key])) {
            $statement = $this->db->prepare('SELECT * FROM ' . $this->identifier($table) . ' WHERE _ID=? LIMIT 1');
            $statement->execute([$id]);
            $cache[$key] = $statement->fetch() ?: [];
        }
        return $this->decodeValue($cache[$key][$column] ?? '');
    }

    private function runCct(array $action, array &$values, array &$inserted, array $user): void
    {
        $settings = (array) ($action['settings']['insert_custom_content_type'] ?? []);
        $type = preg_replace('/[^a-z0-9_]/', '', (string) ($settings['type'] ?? ''));
        $table = $this->table($type);
        $columns = $this->tableColumns($table);
        if ($type === '' || !$columns) throw new RuntimeException('No existe la tabla CCT para ' . $type . '.');
        $data = [];
        $recordId = 0;
        foreach ((array) ($settings['fields_map'] ?? []) as $source => $target) {
            if ($target === '') continue;
            $value = $this->sourceValue((string) $source, $values, $inserted);
            if ($target === '_ID') { $recordId = (int) $value; continue; }
            if (isset($columns[$target])) $data[$target] = $this->encodeValue($value, $columns[$target]);
        }
        foreach ((array) ($settings['default_fields'] ?? []) as $column => $value) {
            if (isset($columns[$column])) $data[$column] = $this->encodeValue($value, $columns[$column]);
        }
        if (isset($columns['cct_status']) && !isset($data['cct_status'])) $data['cct_status'] = (string) ($settings['status'] ?? 'publish');
        if (isset($columns['cct_author_id']) && $recordId < 1) $data['cct_author_id'] = (int) $user['id'];
        if (isset($columns['cct_modified'])) $data['cct_modified'] = gmdate('Y-m-d H:i:s');
        if ($recordId > 0) {
            $sets = implode(',', array_map(fn ($name) => $this->identifier($name) . '=?', array_keys($data)));
            $statement = $this->db->prepare('UPDATE ' . $this->identifier($table) . ' SET ' . $sets . ' WHERE _ID=?');
            $statement->execute([...array_values($data), $recordId]);
        } else {
            $names = array_keys($data);
            $statement = $this->db->prepare('INSERT INTO ' . $this->identifier($table) . ' (' . implode(',', array_map([$this, 'identifier'], $names)) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')');
            $statement->execute(array_values($data));
            $recordId = (int) $this->db->lastInsertId();
        }
        $inserted[$type] = $recordId;
        if ($type === 'inventario') {
            $values['id_inventario'] = $recordId;
            $values['inserted_cct_inventario'] = $recordId;
        }
    }

    private function runPost(array $action, array $values, array $inserted): void
    {
        $posts = $this->prefix() . 'posts';
        $metaTable = $this->prefix() . 'postmeta';
        if (!$this->tableExists($posts) || !$this->tableExists($metaTable)) return;
        $settings = (array) ($action['settings']['insert_post'] ?? []);
        $postId = 0;
        $postData = [];
        $meta = [];
        foreach ((array) ($settings['fields_map'] ?? []) as $source => $target) {
            if ($target === '') continue;
            $value = $this->sourceValue((string) $source, $values, $inserted);
            if ($target === 'ID') $postId = (int) $value;
            elseif (in_array($target, ['post_title', 'post_content', 'post_excerpt', 'post_status'], true)) $postData[$target] = $value;
            else $meta[$target] = $value;
        }
        if ($postId < 1) throw new RuntimeException('No se recibió el inmueble que debe actualizarse.');
        if ($postData) {
            $sets = implode(',', array_map(fn ($name) => $this->identifier($name) . '=?', array_keys($postData)));
            $statement = $this->db->prepare('UPDATE ' . $this->identifier($posts) . ' SET ' . $sets . ' WHERE ID=?');
            $statement->execute([...array_values($postData), $postId]);
        }
        foreach ($meta as $key => $value) {
            $encoded = is_array($value) ? serialize($value) : (string) $value;
            $update = $this->db->prepare('UPDATE ' . $this->identifier($metaTable) . ' SET meta_value=? WHERE post_id=? AND meta_key=?');
            $update->execute([$encoded, $postId, $key]);
            if ($update->rowCount() === 0) {
                $exists = $this->db->prepare('SELECT COUNT(*) FROM ' . $this->identifier($metaTable) . ' WHERE post_id=? AND meta_key=?');
                $exists->execute([$postId, $key]);
                if ((int) $exists->fetchColumn() === 0) {
                    $insert = $this->db->prepare('INSERT INTO ' . $this->identifier($metaTable) . ' (post_id,meta_key,meta_value) VALUES (?,?,?)');
                    $insert->execute([$postId, $key, $encoded]);
                }
            }
        }
    }

    private function dispatchExternal(array $action, string $mode, int $inventoryId, array $values, array $inserted, array $user): void
    {
        $type = (string) ($action['type'] ?? '');
        $name = $type === 'call_hook'
            ? (string) ($action['settings']['call_hook']['hook_name'] ?? 'inventory-hook')
            : 'inventory-webhook';
        $payload = ['mode' => $mode, 'inventoryId' => $inventoryId, 'values' => $values, 'insertedIds' => $inserted];
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare('INSERT INTO skc_app_events (event_name,aggregate_type,aggregate_id,payload_json,status,created_at) VALUES (?,?,?,?,?,?)');
        $statement->execute([$name, 'inventario', (string) $inventoryId, $this->json($payload), 'pending', $now]);
        $eventId = (int) $this->db->lastInsertId();

        if ($type === 'call_hook') {
            $queued = $this->enqueueReportNotifications($name, $mode, $inventoryId, $payload, $user);
            if ($queued > 0) $this->markEvent($eventId, 'processed');
            return;
        }
        if (!Env::bool('INVENTORY_EXTERNAL_ACTIONS', false)) return;
        // Los webhooks son credenciales operativas: nunca se leen del esquema versionado.
        $url = trim((string) Env::get('INVENTORY_WEBHOOK_URL', ''));
        if ($url === '') return;
        try {
            $this->postJson($url, $payload);
            $this->markEvent($eventId, 'processed');
        } catch (Throwable) {
            // El outbox queda pendiente para reintento; el inventario ya fue confirmado.
        }
    }

    private function enqueueReportNotifications(string $hook, string $mode, int $inventoryId, array $payload, array $user): int
    {
        if (!$this->tableExists('skc_notification_queue')) return 0;
        $recipients = array_filter(array_map('trim', explode(',', (string) Env::get('INVENTORY_REPORT_RECIPIENTS', ''))));
        $count = 0;
        $now = gmdate('Y-m-d H:i:s');
        foreach ($recipients as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $dedupe = 'inventory:' . $hook . ':' . $mode . ':' . $inventoryId . ':' . strtolower($email);
            $sql = 'INSERT INTO skc_notification_queue (project_code,source_module,channel,provider,destination,destination_name,subject,message_text,payload_json,meta_json,status,priority,attempts,max_attempts,dedupe_key,scheduled_at,next_attempt_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $statement = $this->db->prepare($sql);
            try {
                $statement->execute([
                    'skc-operations', 'inventory/' . $hook, 'email', Env::get('NOTIFICATION_EMAIL_PROVIDER', 'smtp'),
                    strtolower($email), null, 'Inventario #' . $inventoryId . ' registrado',
                    'Se completó el flujo ' . $mode . ' del inventario #' . $inventoryId . '.',
                    $this->json($payload), $this->json(['inventory_id' => $inventoryId, 'hook' => $hook]),
                    'pending', 5, 0, 5, $dedupe, $now, $now, (string) ($user['email'] ?? $user['id']), $now, $now,
                ]);
                $count++;
            } catch (Throwable $error) {
                if (!str_contains(strtolower($error->getMessage()), 'duplicate')) throw $error;
            }
        }
        return $count;
    }

    private function markEvent(int $id, string $status): void
    {
        $statement = $this->db->prepare('UPDATE skc_app_events SET status=?,processed_at=? WHERE id=?');
        $statement->execute([$status, gmdate('Y-m-d H:i:s'), $id]);
    }

    private function postJson(string $url, array $payload, array $headers = []): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
            throw new RuntimeException('Endpoint externo inválido.');
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ip = gethostbyname($host);
        if (!Env::bool('ALLOW_PRIVATE_WEBHOOKS', false) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('El endpoint resuelve a una red privada no autorizada.');
        }
        if (!function_exists('curl_init')) throw new RuntimeException('La extensión cURL es requerida.');
        $curl = curl_init($url);
        $timeout = max(20, min(120, (int) Env::get('MINIMAX_TIMEOUT_SECONDS', '75')));
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_POSTFIELDS => $this->json($payload),
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body === false || $status < 200 || $status >= 300) {
            $remote = json_decode((string) $body, true);
            $message = (string) ($remote['base_resp']['status_msg'] ?? $remote['error']['message'] ?? $remote['message'] ?? '');
            if ($error !== '') {
                $isTimeout = str_contains(mb_strtolower($error), 'timed out');
                throw new RuntimeException($isTimeout
                    ? 'MiniMax tardó demasiado en responder. Intenta nuevamente con una descripción más corta.'
                    : 'No se pudo conectar con MiniMax. Verifica la salida HTTPS del servidor.');
            }
            throw new RuntimeException($message !== ''
                ? 'MiniMax: ' . mb_substr($message, 0, 300)
                : 'MiniMax respondió HTTP ' . $status . '. Verifica la API key, el modelo y el saldo de la cuenta.');
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function minimaxEndpoint(): string
    {
        $endpoint = trim((string) Env::get('MINIMAX_ENDPOINT', ''), " \n\r\t\v\0\"'");
        if (preg_match('/^MINIMAX_ENDPOINT\s*=\s*(.+)$/i', $endpoint, $matches)) {
            $endpoint = trim($matches[1], " \n\r\t\v\0\"'");
        }
        if ($endpoint === '') {
            return self::MINIMAX_CHAT_ENDPOINT;
        }
        if (!preg_match('#^https?://#i', $endpoint) && preg_match('#^api\.minimax\.io(?:/|$)#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }
        $endpoint = rtrim($endpoint, '/');
        if (preg_match('#^https://api\.minimax\.io(?:/v1)?$#i', $endpoint)) {
            return self::MINIMAX_CHAT_ENDPOINT;
        }
        if (filter_var($endpoint, FILTER_VALIDATE_URL)
            && in_array((string) parse_url($endpoint, PHP_URL_SCHEME), ['https', 'http'], true)) {
            return $endpoint;
        }
        error_log('[Form Studio AI] MINIMAX_ENDPOINT inválido; se utilizará el endpoint oficial.');
        return self::MINIMAX_CHAT_ENDPOINT;
    }

    private function draft(int $userId, string $key): array
    {
        $statement = $this->db->prepare('SELECT revision,payload,updated_at FROM ' . $this->identifier($this->draftTable()) . ' WHERE user_id=? AND draft_key=? LIMIT 1');
        $statement->execute([$userId, $key]);
        $row = $statement->fetch();
        if (!$row) return [];
        return ['revision' => (int) $row['revision'], 'values' => (array) json_decode((string) $row['payload'], true), 'updatedAt' => (string) $row['updated_at']];
    }

    private function draftKey(int $userId, string $mode, array $context): string
    {
        ksort($context);
        return hash('sha256', $this->json(['user' => $userId, 'mode' => $mode, 'context' => $context]));
    }

    private function filterValues(string $mode, array $values): array
    {
        $allowed = [];
        foreach ($this->items($this->form($mode)) as $item) if (!empty($item['name'])) $allowed[$item['name']] = true;
        return array_intersect_key($this->sanitizeArray($values), $allowed);
    }

    private function sanitizeArray(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            $key = is_int($key) ? $key : preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $key);
            if (is_array($value)) $clean[$key] = $this->sanitizeArray($value);
            elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) $clean[$key] = $value;
            else $clean[$key] = mb_substr(trim((string) $value), 0, 100000);
        }
        return $clean;
    }

    private function items(array $form): array
    {
        $items = [];
        foreach ((array) ($form['sections'] ?? []) as $section) {
            foreach ((array) ($section['items'] ?? []) as $item) {
                if (in_array($item['kind'] ?? '', ['field', 'repeater'], true)) $items[] = $item;
            }
        }
        return $items;
    }

    private function shouldRun(array $action, array $values): bool
    {
        foreach ((array) ($action['conditions'] ?? []) as $condition) {
            $actual = $values[(string) ($condition['field'] ?? '')] ?? '';
            $expected = $condition['default'] ?? $condition['value'] ?? '';
            $a = is_array($actual) ? implode(',', $actual) : (string) $actual;
            $e = is_array($expected) ? implode(',', $expected) : (string) $expected;
            $matches = match ((string) ($condition['operator'] ?? 'equal')) {
                'not_equal' => $a !== $e, 'less' => (float) $a < (float) $e,
                'greater' => (float) $a > (float) $e, 'contain' => str_contains($a, $e),
                'not_contain' => !str_contains($a, $e), 'empty' => $a === '', 'not_empty' => $a !== '',
                default => $a === $e,
            };
            if (!$matches) return false;
        }
        return true;
    }

    private function sourceValue(string $source, array $values, array $inserted): mixed
    {
        if (str_starts_with($source, 'inserted_cct_')) return $inserted[substr($source, 13)] ?? $values[$source] ?? '';
        return $values[$source] ?? '';
    }

    private function encodeValue(mixed $value, string $type): mixed
    {
        if (is_array($value) || is_object($value)) return serialize($value);
        if (str_contains($type, 'bigint') && $value !== '') {
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return strtotime($value . ' 00:00:00 UTC');
            return (int) $value;
        }
        return $value === null ? '' : (string) $value;
    }

    private function decodeValue(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') return $value;
        if (preg_match('/^(?:a|O|s|i|b|d|N):/', $value)) {
            $decoded = @unserialize($value, ['allowed_classes' => false]);
            if ($decoded !== false || $value === 'b:0;') return $decoded;
        }
        if (($value[0] === '[' || $value[0] === '{') && is_array($json = json_decode($value, true))) return $json;
        return $value;
    }

    private function normalizeFieldValue(mixed $value, string $fieldType): mixed
    {
        if ($fieldType === 'date' && is_numeric($value) && (int) $value > 100000000) {
            return gmdate('Y-m-d', (int) $value);
        }
        return $value;
    }

    private function tableColumns(string $table): array
    {
        if (isset($this->columns[$table])) return $this->columns[$table];
        if (!$this->tableExists($table)) return $this->columns[$table] = [];
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $rows = $this->db->query('SHOW COLUMNS FROM ' . $this->identifier($table))->fetchAll();
            foreach ($rows as $row) $this->columns[$table][$row['Field']] = strtolower((string) $row['Type']);
        } else {
            $rows = $this->db->query('PRAGMA table_info(' . $this->identifier($table) . ')')->fetchAll();
            foreach ($rows as $row) $this->columns[$table][$row['name']] = strtolower((string) $row['type']);
        }
        return $this->columns[$table] ?? [];
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) return $this->tableCache[$table];
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $statement = $this->db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $statement->execute([$table]);
        } else {
            $statement = $this->db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
            $statement->execute([$table]);
        }
        return $this->tableCache[$table] = (int) $statement->fetchColumn() > 0;
    }

    private function table(string $type): string { return $this->prefix() . 'jet_cct_' . $type; }
    private function draftTable(): string { return $this->prefix() . 'skc_inventory_app_drafts'; }
    private function prefix(): string { return (string) Env::get('LEGACY_TABLE_PREFIX', 'wp_'); }
    private function identifier(string $value): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $value)) throw new RuntimeException('Identificador SQL inválido.');
        return '`' . $value . '`';
    }
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
