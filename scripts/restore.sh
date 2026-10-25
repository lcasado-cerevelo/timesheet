#!/bin/sh
# Restaura un respaldo de Tiempo sobre el servidor actual (REEMPLAZA la base y los archivos).
#   ./scripts/restore.sh backups/tiempo-db-AAAAMMDD-HHMMSS.sql.gz backups/tiempo-archivos-AAAAMMDD-HHMMSS.tar.gz
# En un servidor nuevo: 1) pon el mismo .env.prod (con la MISMA APP_KEY), 2) levanta el compose, 3) corre este script.
# Opciones por variable de entorno: COMPOSE="docker compose -f docker-compose.yml" (desarrollo), YES=1 (no preguntar).
set -eu
export MSYS_NO_PATHCONV=1   # en Git Bash de Windows, evita que reescriba las rutas de Linux que se pasan a docker
cd "$(dirname "$0")/.."
[ "$#" -eq 2 ] || { echo "Uso: $0 <respaldo-db.sql.gz> <respaldo-archivos.tar.gz>" >&2; exit 2; }
DB_FILE=$1; FILES_FILE=$2
[ -f "$DB_FILE" ] && [ -f "$FILES_FILE" ] || { echo "No encuentro alguno de los archivos de respaldo." >&2; exit 2; }
gzip -t "$DB_FILE" && gzip -t "$FILES_FILE" || { echo "Un respaldo está dañado; no se toca nada." >&2; exit 1; }
COMPOSE=${COMPOSE:-"docker compose -f docker-compose.prod.yml --env-file .env.prod"}

if [ "${YES:-0}" != "1" ]; then
    echo "Esto REEMPLAZA la base de datos y los archivos de este servidor con:"
    echo "  $DB_FILE"
    echo "  $FILES_FILE"
    printf "Escribe RESTAURAR para continuar: "
    read -r ans
    [ "$ans" = "RESTAURAR" ] || { echo "Cancelado."; exit 1; }
fi

echo "[restaurar] base de datos..."
gunzip -c "$DB_FILE" | $COMPOSE exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'

echo "[restaurar] archivos..."
$COMPOSE exec -T app sh -c 'find /var/www/html/storage/app -mindepth 1 -maxdepth 1 ! -name ".gitignore" -exec rm -rf {} +'
$COMPOSE exec -T app tar -C /var/www/html/storage/app -xzf - < "$FILES_FILE"
$COMPOSE exec -T app sh -c 'chown -R www-data:www-data /var/www/html/storage/app && chmod -R ug+rwX /var/www/html/storage/app'

echo "[restaurar] comprobando que la aplicación lee los datos..."
$COMPOSE exec -T app php artisan migrate:status --no-interaction | tail -3
echo "[restaurar] listo. Si cambió la APP_KEY, las credenciales cifradas (Accountisi) habrá que escribirlas de nuevo en Integración."
