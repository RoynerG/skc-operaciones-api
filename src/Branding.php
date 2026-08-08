<?php

declare(strict_types=1);

namespace SKC\FormStudio;

use PDO;
use Throwable;

final class Branding
{
    private const DEFAULT_LOGO = 'https://sucasainmobiliaria.com.co/wp-content/uploads/2022/05/logo-white-skc-e1781617234571.png';
    private const DEFAULT_FAVICON = 'https://sucasainmobiliaria.com.co/wp-content/uploads/2026/06/cropped-ISOLOGO-WEB.png';

    private array $cache = [];

    public function __construct(private readonly PDO $db)
    {
    }

    public function publicConfig(): array
    {
        return [
            'organizationName' => 'Su Casa Inmobiliaria',
            'productName' => 'SKC Operaciones',
            'logoUrl' => $this->systemImage('portal_logo_url', self::DEFAULT_LOGO),
            'faviconUrl' => $this->systemImage('portal_favicon_url', self::DEFAULT_FAVICON),
            'colors' => [
                'primary' => '#1B447D',
                'accent' => '#F59120',
                'highlight' => '#F8CF4A',
                'ink' => '#404041',
                'muted' => '#635F5A',
                'surface' => '#FFFFFF',
            ],
        ];
    }

    private function systemImage(string $function, string $fallback): string
    {
        if (array_key_exists($function, $this->cache)) {
            return $this->cache[$function];
        }

        try {
            $table = (string) Env::get('LEGACY_TABLE_PREFIX', 'wp_') . 'jet_cct_confi_sistema';
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                return $this->cache[$function] = $fallback;
            }
            $statement = $this->db->prepare(
                "SELECT COALESCE(NULLIF(valor, ''), NULLIF(imagen, '')) AS image_url FROM `" . $table . '` WHERE funcion = ? LIMIT 1'
            );
            $statement->execute([$function]);
            $url = trim((string) ($statement->fetchColumn() ?: ''));
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                return $this->cache[$function] = $url;
            }
        } catch (Throwable) {
            // La interfaz conserva el fallback institucional si la tabla no está disponible.
        }

        return $this->cache[$function] = $fallback;
    }
}
