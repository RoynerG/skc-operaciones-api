<?php

/**
 * Allowlisted native PHP functions available to form actions.
 * Add business functions here; never execute function names received directly
 * from a public request.
 */
return [
    'registrar_auditoria' => static function (array $context): array {
        error_log(sprintf(
            '[Form Studio] form=%s submission=%d fields=%d',
            $context['form']['slug'],
            $context['submission']['id'],
            count($context['values'])
        ));
        return ['ok' => true, 'message' => 'Auditoría registrada en el log del servidor.'];
    },
];
