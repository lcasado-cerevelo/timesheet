#!/bin/sh
# Respaldo de Tiempo: la base de datos (MariaDB) y los archivos (logos y PDF de facturas), en la carpeta backups/.
#   ./scripts/backup.sh                       producción (docker-compose.prod.yml + .env.prod)
#   COMPOSE="docker compose -f docker-compose.yml" ./scripts/backup.sh     el entorno de desarrollo
# NO incluye APP_KEY ni .env.prod: guárdalos aparte y en un lugar seguro. Sin la misma APP_KEY, lo cifrado
# (por ejemplo la contraseña de Accountisi) no se puede leer después de restaurar.
set -eu
export MSYS_NO_PATHCONV=1   # en Git Bash de Windows, evita que reescriba las rutas de Linux que se pasan a docker
cd "$(dirname "$0")/.."
COMPOSE=${COMPOSE:-"docker compose -f docker-compose.prod.yml --env-file .env.prod"}
OUT=${BACKUP_DIR:-backups}
STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p "$OUT"

echo "[respaldo] base de datos..."
$COMPOSE exec -T db sh -c 'mariadb-dump --single-transaction --routines --triggers --default-character-set=utf8mb4 -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
    | gzip > "$OUT/tiempo-db-$STAMP.sql.gz"

echo "[respaldo] archivos (logos y facturas en PDF)..."
$COMPOSE exec -T app tar -C /var/www/html/storage/app -czf - . > "$OUT/tiempo-archivos-$STAMP.tar.gz"

# Un respaldo vacío o corrupto es peor que ninguno: se comprueba antes de decir que terminó.
for f in "$OUT/tiempo-db-$STAMP.sql.gz" "$OUT/tiempo-archivos-$STAMP.tar.gz"; do
    gzip -t "$f" || { echo "[respaldo] ERROR: $f está dañado" >&2; exit 1; }
done
if ! gunzip -c "$OUT/tiempo-db-$STAMP.sql.gz" | grep -q "CREATE TABLE"; then
    echo "[respaldo] ERROR: el respaldo de la base no contiene tablas" >&2; exit 1
fi

ls -l "$OUT/tiempo-db-$STAMP.sql.gz" "$OUT/tiempo-archivos-$STAMP.tar.gz" | awk '{print "[respaldo] " $5 " bytes  " $9}'
echo "[respaldo] listo. Guarda estos dos archivos fuera del servidor (por ejemplo en S3) y recuerda guardar aparte la APP_KEY."
