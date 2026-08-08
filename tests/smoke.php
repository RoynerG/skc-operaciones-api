<?php

declare(strict_types=1);

use SKC\FormStudio\ActionRunner;
use SKC\FormStudio\Auth;
use SKC\FormStudio\Branding;
use SKC\FormStudio\Database;
use SKC\FormStudio\FormRepository;
use SKC\FormStudio\InventoryAiMapper;
use SKC\FormStudio\InventoryModule;

define('FORM_STUDIO_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    $prefix = 'SKC\\FormStudio\\';
    if (str_starts_with($class, $prefix)) {
        require FORM_STUDIO_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

$database = sys_get_temp_dir() . '/skc-form-studio-' . bin2hex(random_bytes(5)) . '.sqlite';
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $database);
putenv('ADMIN_EMAIL=smoke@skc.local');
putenv('ADMIN_PASSWORD=SmokePass123');
putenv('APP_URL=http://localhost:8080');
putenv('AUTH_PROVIDER=funcionarios');
putenv('AUTH_FUNCIONARIOS_TABLE=wp_jet_cct_funcionarios');

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $db = Database::connection();
    $branding = (new Branding($db))->publicConfig();
    check(($branding['colors']['primary'] ?? '') === '#1B447D', 'El branding institucional no cargó la paleta esperada.');
    check(filter_var($branding['logoUrl'] ?? '', FILTER_VALIDATE_URL) !== false, 'El branding no devolvió un logo válido.');
    $db->exec("CREATE TABLE wp_jet_cct_funcionarios (
        _ID INTEGER PRIMARY KEY, id_empleado TEXT, user_others_apss TEXT, pass_others_apss TEXT,
        nombre TEXT, correo TEXT, rol TEXT, activo TEXT
    )");
    $statement = $db->prepare('INSERT INTO wp_jet_cct_funcionarios (_ID,id_empleado,user_others_apss,pass_others_apss,nombre,correo,rol,activo) VALUES (?,?,?,?,?,?,?,?)');
    $statement->execute([101, '1', 'smoke-user', 'SmokePass123', 'Funcionario Smoke', 'smoke@skc.local', 'Desarrollo', 'Si']);
    $statement->execute([102, '2', 'blocked-user', 'BlockedPass123', 'Funcionario Inactivo', 'blocked@skc.local', 'Desarrollo', 'No']);
    $statement = null;
    $auth = new Auth($db);
    check($auth->login('smoke-user', 'clave-incorrecta') === null, 'Aceptó una clave incorrecta.');
    check($auth->login('blocked-user', 'BlockedPass123') === null, 'Permitió ingresar a un funcionario inactivo.');
    $session = $auth->login('smoke-user', 'SmokePass123');
    check(is_array($session) && strlen($session['token']) === 64, 'Falló el inicio de sesión de funcionarios.');
    check(($session['user']['id'] ?? 0) === 1, 'La sesión no utilizó id_empleado como user_id.');
    check(($session['user']['username'] ?? '') === 'smoke-user', 'No presentó el usuario de otras aplicaciones.');

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $session['token'];
    check($auth->userFromRequest()['email'] === 'smoke@skc.local', 'El token no recuperó al usuario.');

    $inventory = new InventoryModule($db);
    putenv("MINIMAX_ENDPOINT=MINIMAX_ENDPOINT='api.minimax.io/v1'");
    $endpointMethod = new ReflectionMethod($inventory, 'minimaxEndpoint');
    check($endpointMethod->invoke($inventory) === 'https://api.minimax.io/v1/chat/completions', 'No normalizó el endpoint de MiniMax.');
    putenv('MINIMAX_ENDPOINT');
    $inventoryBoot = $inventory->bootstrap('add', ['id_inmueble' => '77'], $session['user']);
    check(count($inventoryBoot['schema']['sections'] ?? []) === 12, 'No cargó las 12 secciones del inventario migrado.');
    $inventoryDraft = $inventory->saveDraft('add', ['id_inmueble' => '77'], [
        'draftKey' => $inventoryBoot['draftKey'], 'revision' => 0,
        'patch' => ['direccion' => 'Dirección de prueba', 'campo_no_permitido' => 'ignorar'],
    ], $session['user']);
    $inventoryReload = $inventory->bootstrap('add', ['id_inmueble' => '77'], $session['user']);
    check($inventoryDraft['revision'] === 1 && ($inventoryReload['values']['direccion'] ?? '') === 'Dirección de prueba', 'Falló el borrador del inventario especializado.');
    check(!array_key_exists('campo_no_permitido', $inventoryReload['values']), 'El inventario aceptó un campo no permitido.');
    $inventory->deleteDraft('add', ['id_inmueble' => '77'], $session['user']);

    $aiMapper = new InventoryAiMapper();
    $aiSpecification = $aiMapper->specification([
        'items' => [[
            'kind' => 'repeater', 'name' => 'sala', 'label' => 'Sala',
            'fields' => [
                ['name' => 'descripcion_sala', 'label' => 'Elemento', 'type' => 'select', 'glossaryId' => 669],
                ['name' => 'cantidad_sala', 'label' => 'Cantidad', 'type' => 'text'],
                ['name' => 'tipo_de_material_sala', 'label' => 'Material', 'type' => 'text'],
                ['name' => 'estado_sala', 'label' => 'Estado', 'type' => 'select', 'glossaryId' => 673],
            ],
        ]],
    ], static fn(int $id): array => $id === 669
        ? [['value' => 'Puertas', 'label' => 'Puertas']]
        : [['value' => 'Bueno', 'label' => 'Bueno'], ['value' => 'Regular', 'label' => 'Regular']]);
    $aiDecoded = $aiMapper->decodeResponse([
        'choices' => [['message' => ['content' => '<think>razonamiento interno</think>```json' . "\n" . '{"values":{"sala":[{"descripcion_sala":"puerta","tipo_de_material_sala":"madera","estado_sala":"bueno"}]}}' . "\n" . '```']]],
        'base_resp' => ['status_code' => 0],
    ]);
    $aiValues = $aiMapper->normalize($aiDecoded, $aiSpecification);
    check(($aiValues['sala'][0]['descripcion_sala'] ?? '') === 'Puertas', 'La IA no normalizó el elemento del repetidor.');
    check(($aiValues['sala'][0]['estado_sala'] ?? '') === 'Bueno', 'La IA no normalizó el estado del repetidor.');
    check(($aiValues['sala'][0]['tipo_de_material_sala'] ?? '') === 'madera', 'La IA no conservó el material dictado.');
    check(($aiValues['sala'][0]['cantidad_sala'] ?? '') === '1', 'La IA no asignó cantidad uno al elemento singular.');

    $forms = new FormRepository($db);
    $definition = FormRepository::starter('prueba-integral', 'Prueba integral');
    $definition['status'] = 'active';
    $definition['actions'] = [[
        'id' => 'audit-test', 'type' => 'server_function', 'enabled' => true, 'functionName' => 'registrar_auditoria',
    ]];
    $form = $forms->create($definition, $session['user']['id']);
    check($form['slug'] === 'prueba-integral' && $form['version'] === 1, 'No se creó el formulario.');
    check(!array_key_exists('actions', $forms->publicDefinition('prueba-integral')), 'El esquema público expone acciones privadas.');

    $definition = $form;
    $definition['description'] = 'Versión actualizada';
    $form = $forms->update('prueba-integral', $definition);
    check($form['version'] === 2, 'No avanzó la versión del formulario.');

    $draft = $forms->saveDraft($form, 'smokedraft123', 'smokeclient12345', ['nombre' => 'Borrador'], 0);
    $loadedDraft = $forms->getDraft($form, 'smokedraft123', 'smokeclient12345');
    check($draft['revision'] === 1 && $loadedDraft['values']['nombre'] === 'Borrador', 'Falló el borrador.');

    $submission = $forms->createSubmission($form, ['nombre' => 'Envío completo', 'campo_no_permitido' => 'ignorar'], ['source' => 'smoke']);
    check(!array_key_exists('campo_no_permitido', $submission['values']), 'Se almacenó un campo no permitido.');
    $results = (new ActionRunner())->run($form, $submission);
    $forms->updateActionResult($submission['id'], $results);
    check(($results[0]['status'] ?? '') === 'success', 'No se ejecutó la función PHP permitida.');
    check(count($forms->submissions($form)) === 1, 'No se listó el envío.');

    check($forms->deleteDraft($form, 'smokedraft123', 'smokeclient12345'), 'No se eliminó el borrador.');
    check($forms->delete('prueba-integral'), 'No se eliminó el formulario.');

    echo json_encode([
        'status' => 'ok', 'auth' => true, 'formVersion' => 2, 'draftRevision' => 1,
        'submissionId' => $submission['id'], 'actionStatus' => $results[0]['status'],
        'inventorySections' => count($inventoryBoot['schema']['sections']), 'inventoryDraft' => true,
        'aiRepeaterMapping' => true, 'branding' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    unset($inventory, $forms, $auth, $db);
    Database::disconnect();
    gc_collect_cycles();
    if (is_file($database)) {
        unlink($database);
    }
}
