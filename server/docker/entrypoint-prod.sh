#!/bin/sh
# Arranque de producción: verifica la configuración, espera la base, migra si se pidió y deja la aplicación con sus cachés.
set -e
cd /var/www/html

# Sin clave no se arranca: con otra clave distinta no se podrían leer los datos cifrados (p. ej. la contraseña de Accountisi).
if [ -z "${APP_KEY:-}" ]; then
    echo "[tiempo] ERROR: falta APP_KEY. Debe ser la MISMA de siempre (guárdala aparte; php artisan key:generate --show crea una nueva)." >&2
    exit 1
fi
if [ "${APP_DEBUG:-false}" = "true" ]; then
    echo "[tiempo] ERROR: APP_DEBUG=true en producción mostraría datos internos. Ponla en false." >&2
    exit 1
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

if [ "${WAIT_FOR_DB:-0}" = "1" ]; then
    echo "[tiempo] esperando la base de datos en ${DB_HOST}:${DB_PORT}..."
    i=0
    until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); } catch (Throwable $e) { exit(1); }'; do
        i=$((i+1)); [ "$i" -gt 60 ] && { echo "[tiempo] la base de datos no respondió" >&2; exit 1; }
        sleep 2
    done
fi

if [ "${AUTO_MIGRATE:-0}" = "1" ]; then
    php artisan migrate --force --no-interaction
fi

# Cachés (leen el entorno de ESTE arranque)
php artisan package:discover --ansi >/dev/null 2>&1 || true
php artisan config:cache --no-interaction >/dev/null
php artisan route:cache --no-interaction >/dev/null
php artisan view:cache --no-interaction >/dev/null

exec "$@"
