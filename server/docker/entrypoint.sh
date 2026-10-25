#!/bin/sh
# Prepara la aplicación al arrancar el contenedor (solo lo que falte) y luego ejecuta el comando pedido.
set -e
cd /var/www/html

# Dependencias: si la carpeta vendor no existe (clon nuevo), se instalan.
if [ ! -f vendor/autoload.php ]; then
    echo "[tiempo] instalando dependencias con composer..."
    composer install --no-interaction --prefer-dist
fi

# Archivo .env y clave de la aplicación
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force --no-interaction
fi

# Carpetas de trabajo
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache
# Los comandos que se corren con `docker compose exec` van como root y dejan archivos que Apache (www-data) no podría escribir;
# en desarrollo se dejan siempre escribibles por todos.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R a+rwX storage bootstrap/cache 2>/dev/null || true

# Espera a la base de datos y migra si se pidió (solo el contenedor "app" lo hace)
if [ "${WAIT_FOR_DB:-0}" = "1" ]; then
    echo "[tiempo] esperando la base de datos en ${DB_HOST}:${DB_PORT}..."
    i=0
    until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); } catch (Throwable $e) { exit(1); }'; do
        i=$((i+1)); [ "$i" -gt 60 ] && { echo "[tiempo] la base de datos no respondió"; exit 1; }
        sleep 2
    done
fi
if [ "${AUTO_MIGRATE:-0}" = "1" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
