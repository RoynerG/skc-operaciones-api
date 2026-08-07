<?php

declare(strict_types=1);

namespace SKC\FormStudio;

use RuntimeException;

final class ActionRunner
{
    private array $functions;

    public function __construct()
    {
        $configured = require FORM_STUDIO_ROOT . '/config/functions.php';
        $this->functions = is_array($configured) ? $configured : [];
    }

    public function availableFunctions(): array
    {
        return array_keys($this->functions);
    }

    public function run(array $form, array $submission): array
    {
        $results = [];
        foreach ((array) ($form['actions'] ?? []) as $action) {
            if (empty($action['enabled'])) {
                continue;
            }
            $started = microtime(true);
            try {
                if (($action['type'] ?? '') === 'webhook') {
                    $detail = $this->webhook($form, $submission, $action);
                } elseif (($action['type'] ?? '') === 'server_function') {
                    $detail = $this->serverFunction($form, $submission, $action);
                } else {
                    continue;
                }
                $results[] = [
                    'actionId' => $action['id'] ?? '', 'type' => $action['type'], 'status' => 'success',
                    'durationMs' => (int) round((microtime(true) - $started) * 1000), 'detail' => $detail,
                ];
            } catch (\Throwable $error) {
                error_log('[Form Studio action] ' . $error->getMessage());
                $results[] = [
                    'actionId' => $action['id'] ?? '', 'type' => $action['type'] ?? 'unknown', 'status' => 'failed',
                    'durationMs' => (int) round((microtime(true) - $started) * 1000), 'message' => $error->getMessage(),
                ];
            }
        }
        return $results;
    }

    private function webhook(array $form, array $submission, array $action): array
    {
        $url = (string) ($action['url'] ?? '');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('El webhook no tiene una URL HTTP válida.');
        }
        $this->guardWebhookHost($url);
        $payload = json_encode([
            'event' => 'form.submitted', 'form' => ['slug' => $form['slug'], 'title' => $form['title']],
            'submission' => ['id' => $submission['id'], 'createdAt' => $submission['createdAt']],
            'values' => $submission['values'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type: application/json', 'User-Agent: SKC-Form-Studio/1.0'];
        if (!empty($action['secret'])) {
            $headers[] = 'X-Form-Signature: sha256=' . hash_hmac('sha256', $payload, (string) $action['secret']);
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $action['method'] ?? 'POST', CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($body === false || $error !== '') {
            throw new RuntimeException('No se pudo conectar con el webhook: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('El webhook respondió HTTP ' . $status . '.');
        }
        return ['httpStatus' => $status, 'response' => mb_substr((string) $body, 0, 1000)];
    }

    private function serverFunction(array $form, array $submission, array $action): array
    {
        $name = (string) ($action['functionName'] ?? '');
        if ($name === '' || !isset($this->functions[$name]) || !is_callable($this->functions[$name])) {
            throw new RuntimeException('La función PHP configurada no está registrada.');
        }
        $result = ($this->functions[$name])([
            'form' => $form, 'submission' => $submission, 'values' => $submission['values'],
        ]);
        return is_array($result) ? $result : ['result' => $result];
    }

    private function guardWebhookHost(string $url): void
    {
        if (Env::bool('ALLOW_PRIVATE_WEBHOOKS', false)) {
            return;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $addresses = array_unique(array_filter(array_merge(
            gethostbynamel($host) ?: [],
            filter_var($host, FILTER_VALIDATE_IP) ? [$host] : []
        )));
        if (!$addresses) {
            throw new RuntimeException('No se pudo resolver el host del webhook.');
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Los webhooks hacia redes privadas están deshabilitados.');
            }
        }
    }
}
