# SKC Operaciones API

API PHP 8.2 nativa para los módulos operativos de SKC. El primer módulo es Inventario inmobiliario y trabaja directamente sobre las tablas MySQL actuales sin cargar WordPress.

## Producción

- URL: `https://apiskccbo2.sucasainmobiliaria.com.co`
- Frontend autorizado: `https://portal-formsbcop2.sucasainmobiliaria.com.co`
- Document root recomendado: la raíz del repositorio o `public/`.
- Entrada de salud: `GET /api/health`.

1. Copia `.env.production.example` como `.env` dentro del servidor.
2. Completa `APP_KEY`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`.
3. Comprueba que PHP tenga `pdo_mysql`, `mbstring`, `json` y `curl`.
4. Mantén `INVENTORY_EXTERNAL_ACTIONS=false` durante las primeras pruebas.
5. Verifica `https://apiskccbo2.sucasainmobiliaria.com.co/api/health`.

El archivo `.env`, las bases SQLite y los logs están excluidos de Git.

## Desarrollo y pruebas

```powershell
Copy-Item .env.example .env
php -S 127.0.0.1:8080 router.php
php tests/smoke.php
```

Consulta `.env.production.example` para todas las variables requeridas.
