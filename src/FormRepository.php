<?php

declare(strict_types=1);

namespace SKC\FormStudio;

use InvalidArgumentException;
use PDO;

final class FormRepository
{
    private const FIELD_TYPES = ['text', 'email', 'tel', 'url', 'number', 'date', 'time', 'textarea', 'select', 'radio', 'checkbox'];

    public function __construct(private readonly PDO $db)
    {
    }

    public static function starter(string $slug = 'nuevo-formulario', string $title = 'Nuevo formulario'): array
    {
        return [
            'slug' => self::slug($slug) ?: 'nuevo-formulario',
            'title' => trim($title) ?: 'Nuevo formulario',
            'description' => '',
            'status' => 'draft',
            'version' => 1,
            'submitLabel' => 'Enviar formulario',
            'successMessage' => 'Recibimos la información correctamente.',
            'redirectUrl' => '',
            'draftTtlDays' => 15,
            'sections' => [[
                'id' => 'datos-principales',
                'title' => 'Datos principales',
                'description' => '',
                'fields' => [[
                    'id' => 'nombre', 'type' => 'text', 'name' => 'nombre', 'label' => 'Nombre',
                    'description' => '', 'placeholder' => '', 'required' => true, 'options' => [],
                ]],
            ]],
            'actions' => [],
        ];
    }

    public function all(): array
    {
        $rows = $this->db->query(
            'SELECT f.id,f.slug,f.title,f.description,f.status,f.version,f.created_at,f.updated_at,COUNT(s.id) AS submissions
             FROM forms f LEFT JOIN submissions s ON s.form_id=f.id GROUP BY f.id ORDER BY f.updated_at DESC'
        )->fetchAll();
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'], 'slug' => $row['slug'], 'title' => $row['title'],
            'description' => $row['description'], 'status' => $row['status'], 'version' => (int) $row['version'],
            'submissions' => (int) $row['submissions'], 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'],
            'endpoints' => self::endpoints($row['slug']),
        ], $rows);
    }

    public function find(string $slug, bool $publicOnly = false): ?array
    {
        $sql = 'SELECT * FROM forms WHERE slug = ?' . ($publicOnly ? " AND status = 'active'" : '') . ' LIMIT 1';
        $statement = $this->db->prepare($sql);
        $statement->execute([self::slug($slug)]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }
        $definition = json_decode($row['definition_json'], true) ?: [];
        $definition['id'] = (int) $row['id'];
        $definition['version'] = (int) $row['version'];
        $definition['createdAt'] = $row['created_at'];
        $definition['updatedAt'] = $row['updated_at'];
        $definition['endpoints'] = self::endpoints($row['slug']);
        return $definition;
    }

    public function publicDefinition(string $slug): ?array
    {
        $form = $this->find($slug, true);
        if (!$form) {
            return null;
        }
        unset($form['actions'], $form['id'], $form['createdAt'], $form['updatedAt']);
        $form['endpoints'] = ['submit' => self::endpoints($slug)['submit'], 'drafts' => self::endpoints($slug)['drafts']];
        return $form;
    }

    public function create(array $input, int $userId): array
    {
        $definition = $this->sanitize($input);
        $exists = $this->find($definition['slug']);
        if ($exists) {
            throw new InvalidArgumentException('Ya existe un formulario con ese identificador.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'INSERT INTO forms (slug,title,description,status,version,definition_json,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $definition['slug'], $definition['title'], $definition['description'], $definition['status'], 1,
            json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userId, $now, $now,
        ]);
        return $this->find($definition['slug']) ?? $definition;
    }

    public function update(string $slug, array $input): array
    {
        $existing = $this->find($slug);
        if (!$existing) {
            throw new InvalidArgumentException('No se encontró el formulario.');
        }
        $definition = $this->sanitize(array_replace($existing, $input));
        $definition['slug'] = $existing['slug'];
        $expected = isset($input['version']) ? (int) $input['version'] : (int) $existing['version'];
        if ($expected !== (int) $existing['version']) {
            throw new InvalidArgumentException('El formulario cambió en otra sesión. Recarga antes de guardar.');
        }
        $version = (int) $existing['version'] + 1;
        $definition['version'] = $version;
        $statement = $this->db->prepare(
            'UPDATE forms SET title=?,description=?,status=?,version=?,definition_json=?,updated_at=? WHERE slug=? AND version=?'
        );
        $statement->execute([
            $definition['title'], $definition['description'], $definition['status'], $version,
            json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), gmdate('Y-m-d H:i:s'),
            $existing['slug'], $existing['version'],
        ]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('No se pudo guardar porque existe una versión más reciente.');
        }
        return $this->find($existing['slug']) ?? $definition;
    }

    public function delete(string $slug): bool
    {
        $statement = $this->db->prepare('DELETE FROM forms WHERE slug = ?');
        $statement->execute([self::slug($slug)]);
        return $statement->rowCount() === 1;
    }

    public function saveDraft(array $form, string $draftKey, string $clientId, array $values, int $revision): array
    {
        $this->validateClient($draftKey, $clientId);
        $statement = $this->db->prepare('SELECT * FROM drafts WHERE form_id=? AND draft_key=? AND client_id=? LIMIT 1');
        $statement->execute([$form['id'], $draftKey, $clientId]);
        $existing = $statement->fetch();
        if ($existing && (int) $existing['revision'] > $revision) {
            throw new InvalidArgumentException('Existe un borrador más reciente en el servidor.');
        }
        $clean = $this->filterValues($form, $values);
        $nextRevision = $existing ? (int) $existing['revision'] + 1 : 1;
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + ((int) ($form['draftTtlDays'] ?? 15) * 86400));
        if ($existing) {
            $current = json_decode($existing['payload_json'], true) ?: [];
            $clean = array_replace($current, $clean);
            $this->db->prepare('UPDATE drafts SET revision=?,payload_json=?,expires_at=?,updated_at=? WHERE id=?')
                ->execute([$nextRevision, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $expires, $now, $existing['id']]);
        } else {
            $this->db->prepare('INSERT INTO drafts (form_id,draft_key,client_id,revision,payload_json,expires_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$form['id'], $draftKey, $clientId, $nextRevision, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $expires, $now, $now]);
        }
        return ['revision' => $nextRevision, 'updatedAt' => $now];
    }

    public function getDraft(array $form, string $draftKey, string $clientId): ?array
    {
        $this->validateClient($draftKey, $clientId);
        $statement = $this->db->prepare('SELECT revision,payload_json,updated_at FROM drafts WHERE form_id=? AND draft_key=? AND client_id=? AND expires_at>? LIMIT 1');
        $statement->execute([$form['id'], $draftKey, $clientId, gmdate('Y-m-d H:i:s')]);
        $row = $statement->fetch();
        return $row ? ['revision' => (int) $row['revision'], 'values' => json_decode($row['payload_json'], true) ?: [], 'updatedAt' => $row['updated_at']] : null;
    }

    public function deleteDraft(array $form, string $draftKey, string $clientId): bool
    {
        $this->validateClient($draftKey, $clientId);
        $statement = $this->db->prepare('DELETE FROM drafts WHERE form_id=? AND draft_key=? AND client_id=?');
        $statement->execute([$form['id'], $draftKey, $clientId]);
        return $statement->rowCount() > 0;
    }

    public function createSubmission(array $form, array $values, array $metadata): array
    {
        $clean = $this->filterValues($form, $values);
        $errors = $this->validateRequired($form, $clean);
        if ($errors) {
            throw new InvalidArgumentException(json_encode(['validation' => $errors], JSON_UNESCAPED_UNICODE));
        }
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare('INSERT INTO submissions (form_id,status,payload_json,metadata_json,action_result_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?)');
        $statement->execute([
            $form['id'], 'completed', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '[]', $now, $now,
        ]);
        return ['id' => (int) $this->db->lastInsertId(), 'values' => $clean, 'createdAt' => $now];
    }

    public function updateActionResult(int $submissionId, array $result): void
    {
        $this->db->prepare('UPDATE submissions SET action_result_json=?,updated_at=? WHERE id=?')
            ->execute([json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), gmdate('Y-m-d H:i:s'), $submissionId]);
    }

    public function submissions(array $form): array
    {
        $statement = $this->db->prepare('SELECT id,status,payload_json,action_result_json,created_at FROM submissions WHERE form_id=? ORDER BY id DESC LIMIT 250');
        $statement->execute([$form['id']]);
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'], 'status' => $row['status'], 'values' => json_decode($row['payload_json'], true) ?: [],
            'actions' => json_decode($row['action_result_json'], true) ?: [], 'createdAt' => $row['created_at'],
        ], $statement->fetchAll());
    }

    private function sanitize(array $input): array
    {
        $slug = self::slug((string) ($input['slug'] ?? ''));
        $title = trim(strip_tags((string) ($input['title'] ?? '')));
        if ($slug === '' || $title === '') {
            throw new InvalidArgumentException('El nombre y el identificador son obligatorios.');
        }
        $sections = [];
        $names = [];
        foreach ((array) ($input['sections'] ?? []) as $sectionIndex => $section) {
            if (!is_array($section)) continue;
            $fields = [];
            foreach ((array) ($section['fields'] ?? []) as $fieldIndex => $field) {
                if (!is_array($field)) continue;
                $name = self::slug((string) ($field['name'] ?? 'campo-' . ($fieldIndex + 1)));
                if ($name === '' || isset($names[$name])) {
                    throw new InvalidArgumentException('Cada campo necesita un identificador único. Revisa: ' . $name);
                }
                $names[$name] = true;
                $type = in_array(($field['type'] ?? ''), self::FIELD_TYPES, true) ? $field['type'] : 'text';
                $options = [];
                foreach ((array) ($field['options'] ?? []) as $option) {
                    if (!is_array($option)) continue;
                    $value = trim((string) ($option['value'] ?? ''));
                    if ($value !== '') $options[] = ['label' => trim(strip_tags((string) ($option['label'] ?? $value))), 'value' => $value];
                }
                $fields[] = [
                    'id' => self::slug((string) ($field['id'] ?? $name)) ?: $name,
                    'type' => $type, 'name' => $name,
                    'label' => trim(strip_tags((string) ($field['label'] ?? $name))) ?: $name,
                    'description' => trim(strip_tags((string) ($field['description'] ?? ''))),
                    'placeholder' => trim(strip_tags((string) ($field['placeholder'] ?? ''))),
                    'required' => !empty($field['required']), 'options' => $options,
                ];
            }
            $sections[] = [
                'id' => self::slug((string) ($section['id'] ?? 'seccion-' . ($sectionIndex + 1))) ?: 'seccion-' . ($sectionIndex + 1),
                'title' => trim(strip_tags((string) ($section['title'] ?? 'Sección ' . ($sectionIndex + 1)))),
                'description' => trim(strip_tags((string) ($section['description'] ?? ''))), 'fields' => $fields,
            ];
        }
        if (!$sections) {
            throw new InvalidArgumentException('El formulario necesita al menos una sección.');
        }

        return [
            'slug' => $slug, 'title' => $title,
            'description' => trim(strip_tags((string) ($input['description'] ?? ''))),
            'status' => in_array(($input['status'] ?? ''), ['draft', 'active', 'archived'], true) ? $input['status'] : 'draft',
            'version' => max(1, (int) ($input['version'] ?? 1)),
            'submitLabel' => trim(strip_tags((string) ($input['submitLabel'] ?? 'Enviar formulario'))) ?: 'Enviar formulario',
            'successMessage' => trim(strip_tags((string) ($input['successMessage'] ?? 'Recibimos la información correctamente.'))),
            'redirectUrl' => filter_var($input['redirectUrl'] ?? '', FILTER_VALIDATE_URL) ? (string) $input['redirectUrl'] : '',
            'draftTtlDays' => max(1, min(90, (int) ($input['draftTtlDays'] ?? 15))),
            'sections' => $sections, 'actions' => $this->sanitizeActions((array) ($input['actions'] ?? [])),
        ];
    }

    private function sanitizeActions(array $actions): array
    {
        $clean = [];
        foreach ($actions as $action) {
            if (!is_array($action) || empty($action['type'])) continue;
            $type = (string) $action['type'];
            if ($type === 'webhook') {
                $url = filter_var($action['url'] ?? '', FILTER_VALIDATE_URL) ? (string) $action['url'] : '';
                $clean[] = ['id' => self::slug((string) ($action['id'] ?? bin2hex(random_bytes(4)))), 'type' => 'webhook', 'enabled' => !empty($action['enabled']), 'url' => $url, 'method' => in_array(strtoupper((string) ($action['method'] ?? 'POST')), ['POST', 'PUT', 'PATCH'], true) ? strtoupper((string) $action['method']) : 'POST', 'secret' => trim((string) ($action['secret'] ?? ''))];
            } elseif ($type === 'server_function') {
                $functionName = str_replace('-', '_', self::slug((string) ($action['functionName'] ?? '')));
                $clean[] = ['id' => self::slug((string) ($action['id'] ?? bin2hex(random_bytes(4)))), 'type' => 'server_function', 'enabled' => !empty($action['enabled']), 'functionName' => $functionName];
            }
        }
        return $clean;
    }

    private function filterValues(array $form, array $values): array
    {
        $allowed = [];
        foreach ($form['sections'] as $section) foreach ($section['fields'] as $field) $allowed[$field['name']] = $field;
        $clean = [];
        foreach ($values as $name => $value) {
            if (!isset($allowed[$name])) continue;
            if ($allowed[$name]['type'] === 'checkbox') {
                $clean[$name] = is_array($value) ? array_values(array_map(fn($v) => mb_substr(trim((string) $v), 0, 1000), $value)) : (bool) $value;
            } else {
                $clean[$name] = mb_substr(trim((string) $value), 0, 20000);
            }
        }
        return $clean;
    }

    private function validateRequired(array $form, array $values): array
    {
        $errors = [];
        foreach ($form['sections'] as $section) foreach ($section['fields'] as $field) {
            if (!empty($field['required']) && (!array_key_exists($field['name'], $values) || $values[$field['name']] === '' || $values[$field['name']] === [])) {
                $errors[$field['name']] = 'Este campo es obligatorio.';
            }
        }
        return $errors;
    }

    private function validateClient(string $draftKey, string $clientId): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $draftKey) || !preg_match('/^[a-zA-Z0-9_-]{12,128}$/', $clientId)) {
            throw new InvalidArgumentException('La identificación del borrador no es válida.');
        }
    }

    public static function slug(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($value)) ?? '', '-');
    }

    private static function endpoints(string $slug): array
    {
        $base = rtrim(Env::get('APP_URL', 'http://localhost:8080') ?? '', '/') . '/api';
        return [
            'schema' => "{$base}/public/forms/{$slug}",
            'submit' => "{$base}/public/forms/{$slug}/submissions",
            'drafts' => "{$base}/public/forms/{$slug}/drafts/{draftKey}",
            'submissions' => "{$base}/forms/{$slug}/submissions",
        ];
    }
}
