<?php

declare(strict_types=1);

namespace SKC\FormStudio;

use RuntimeException;

final class InventoryAiMapper
{
    public function specification(array $section, callable $loadGlossary): array
    {
        $allowed = [];
        foreach ((array) ($section['items'] ?? []) as $item) {
            if (($item['kind'] ?? '') === 'field' && ($item['type'] ?? '') !== 'hidden') {
                $allowed[(string) $item['name']] = $this->fieldSpecification($item, $loadGlossary);
                continue;
            }
            if (($item['kind'] ?? '') !== 'repeater') {
                continue;
            }
            $children = [];
            foreach ((array) ($item['fields'] ?? []) as $field) {
                $children[(string) $field['name']] = $this->fieldSpecification($field, $loadGlossary);
            }
            $allowed[(string) $item['name']] = [
                'label' => (string) ($item['label'] ?? $item['name'] ?? ''),
                'type' => 'repeater',
                'format' => 'array_de_objetos',
                'fields' => $children,
            ];
        }
        return $allowed;
    }

    public function decodeResponse(array $response): array
    {
        $serviceCode = (int) ($response['base_resp']['status_code'] ?? 0);
        if ($serviceCode !== 0) {
            throw new RuntimeException((string) ($response['base_resp']['status_msg'] ?? 'MiniMax rechazó la solicitud.'));
        }

        $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
        $content = preg_replace('/<think>.*?<\/think>/su', '', $content) ?? $content;
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', trim($content)) ?? $content;
        $decoded = json_decode(trim($content), true);
        if (!is_array($decoded)) {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');
            $decoded = $start !== false && $end !== false
                ? json_decode(substr($content, $start, $end - $start + 1), true)
                : null;
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('MiniMax no devolvió datos estructurados válidos. Intenta describir cada elemento por separado.');
        }

        $values = $decoded['values'] ?? $decoded;
        return is_array($values) ? $values : [];
    }

    public function normalize(array $suggested, array $specification): array
    {
        $clean = [];
        foreach ($specification as $name => $definition) {
            if (!array_key_exists($name, $suggested)) {
                continue;
            }
            $value = $suggested[$name];
            if (($definition['type'] ?? '') !== 'repeater') {
                $normalized = $this->normalizeField($value, $definition);
                if ($normalized !== '' && $normalized !== [] && $normalized !== null) {
                    $clean[$name] = $normalized;
                }
                continue;
            }

            if (!is_array($value)) {
                continue;
            }
            $rows = array_is_list($value) ? $value : [$value];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $normalizedRow = [];
                foreach ((array) ($definition['fields'] ?? []) as $fieldName => $fieldDefinition) {
                    if (!array_key_exists($fieldName, $row)) {
                        continue;
                    }
                    $fieldValue = $this->normalizeField($row[$fieldName], $fieldDefinition);
                    if ($fieldValue !== '' && $fieldValue !== [] && $fieldValue !== null) {
                        $normalizedRow[$fieldName] = $fieldValue;
                    }
                }
                if ($normalizedRow !== []) {
                    $quantityField = $this->quantityField((array) ($definition['fields'] ?? []));
                    $specialQuantityField = $this->specialQuantityField((array) ($definition['fields'] ?? []));
                    $absenceField = $this->absenceField((array) ($definition['fields'] ?? []));
                    $isAbsent = $absenceField !== null
                        && in_array('Ninguna', (array) ($normalizedRow[$absenceField] ?? []), true);
                    if ($isAbsent) {
                        $observationField = $this->observationField((array) ($definition['fields'] ?? []));
                        if ($observationField !== null) {
                            $normalizedRow[$observationField] = 'Ninguna';
                        }
                        if ($quantityField !== null) unset($normalizedRow[$quantityField]);
                        if ($specialQuantityField !== null) unset($normalizedRow[$specialQuantityField]);
                    } elseif ($specialQuantityField !== null && !empty($normalizedRow[$specialQuantityField])) {
                        if ($quantityField !== null) unset($normalizedRow[$quantityField]);
                    } elseif ($quantityField !== null && empty($normalizedRow[$quantityField])) {
                        $normalizedRow[$quantityField] = '1';
                    }
                    $clean[$name][] = $normalizedRow;
                }
            }
        }
        return $clean;
    }

    private function fieldSpecification(array $field, callable $loadGlossary): array
    {
        $options = array_values(array_filter((array) ($field['options'] ?? []), 'is_array'));
        $glossaryId = (int) ($field['glossaryId'] ?? 0);
        if ($options === [] && $glossaryId > 0) {
            $options = array_values(array_filter((array) $loadGlossary($glossaryId), 'is_array'));
        }
        return [
            'label' => (string) ($field['label'] ?? $field['name'] ?? ''),
            'type' => (string) ($field['type'] ?? 'text'),
            'guide' => $this->fieldGuide((string) ($field['name'] ?? ''), (string) ($field['type'] ?? 'text')),
            'options' => array_map(static fn(array $option): array => [
                'value' => (string) ($option['value'] ?? ''),
                'label' => (string) ($option['label'] ?? $option['value'] ?? ''),
            ], $options),
        ];
    }

    private function normalizeField(mixed $value, array $definition): mixed
    {
        $type = (string) ($definition['type'] ?? 'text');
        $options = (array) ($definition['options'] ?? []);
        if (in_array($type, ['select', 'radio'], true) && $options !== []) {
            return $this->matchOption((string) $value, $options);
        }
        if ($type === 'checkbox' && $options !== []) {
            $values = is_array($value) ? $value : [$value];
            $matched = [];
            foreach ($values as $candidate) {
                $option = $this->matchOption((string) $candidate, $options);
                if ($option !== '') {
                    $matched[] = $option;
                }
            }
            return array_values(array_unique($matched));
        }
        if (is_array($value)) {
            return [];
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        return mb_substr(trim((string) $value), 0, 100000);
    }

    private function matchOption(string $candidate, array $options): string
    {
        $needle = $this->comparisonKey($candidate);
        if ($needle === '') {
            return '';
        }
        foreach ($options as $option) {
            $value = (string) ($option['value'] ?? '');
            $label = (string) ($option['label'] ?? $value);
            if ($needle === $this->comparisonKey($value) || $needle === $this->comparisonKey($label)) {
                return $value;
            }
        }
        return '';
    }

    private function comparisonKey(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
        if (strlen($value) > 4 && str_ends_with($value, 'es')) {
            $value = substr($value, 0, -2);
        } elseif (strlen($value) > 3 && str_ends_with($value, 's')) {
            $value = substr($value, 0, -1);
        }
        return $value;
    }

    private function quantityField(array $fields): ?string
    {
        foreach (array_keys($fields) as $fieldName) {
            if (str_starts_with((string) $fieldName, 'cantidad_')) {
                return (string) $fieldName;
            }
        }
        return null;
    }

    private function specialQuantityField(array $fields): ?string
    {
        foreach (array_keys($fields) as $fieldName) {
            if (str_starts_with((string) $fieldName, 'texto_cantidad_')) {
                return (string) $fieldName;
            }
        }
        return null;
    }

    private function absenceField(array $fields): ?string
    {
        foreach (array_keys($fields) as $fieldName) {
            if (str_starts_with((string) $fieldName, 'ninguna_')) {
                return (string) $fieldName;
            }
        }
        return null;
    }

    private function observationField(array $fields): ?string
    {
        foreach (array_keys($fields) as $fieldName) {
            if (str_starts_with((string) $fieldName, 'observaciones_')) {
                return (string) $fieldName;
            }
        }
        return null;
    }

    private function fieldGuide(string $name, string $type): string
    {
        if (str_starts_with($name, 'descripcion_')) return 'Elemento físico. Elige el valor permitido más cercano, aunque se dicte en singular.';
        if (str_starts_with($name, 'cantidad_')) return 'Cantidad numérica. Usa un número; si no se menciona para un elemento singular, usa 1.';
        if (str_starts_with($name, 'texto_cantidad_')) return 'Alternativa a la cantidad numérica. Úsala únicamente si se dicta Todo el inmueble.';
        if (str_starts_with($name, 'tipo_de_material_')) return 'Material del elemento: madera, metal, vidrio, plástico u otro texto dictado.';
        if (str_starts_with($name, 'estado_')) return 'Estado del elemento. Debe coincidir exactamente con una opción permitida.';
        if (str_starts_with($name, 'observaciones_')) return 'Detalle libre sobre funcionamiento, daños, faltantes o características.';
        if (str_starts_with($name, 'ninguna_')) return 'Marca Ninguna únicamente si se indica que no existe ningún elemento. No la uses para elementos presentes.';
        return in_array($type, ['select', 'radio', 'checkbox'], true)
            ? 'Usa exclusivamente uno de los valores permitidos.'
            : 'Conserva únicamente la información expresada en el dictado.';
    }
}
