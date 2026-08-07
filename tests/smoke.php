<?php

declare(strict_types=1);

use SKC\FormStudio\ActionRunner;
use SKC\FormStudio\Auth;
use SKC\FormStudio\Database;
use SKC\FormStudio\FormRepository;
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

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $db = Database::connection();
    $auth = new Auth($db);
    $session = $auth->login('smoke@skc.local', 'SmokePass123');
    check(is_array($session) && strlen($session['token']) === 64, 'Falló el inicio de sesión.');

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $session['token'];
    check($auth->userFromRequest()['email'] === 'smoke@skc.local', 'El token no recuperó al usuario.');

    $inventory = new InventoryModule($db);
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
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    unset($inventory, $forms, $auth, $db);
    Database::disconnect();
    gc_collect_cycles();
    if (is_file($database)) {
        unlink($database);
    }
}
