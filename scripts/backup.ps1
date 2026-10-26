<#
  Respaldo de Tiempo en Windows: la base de datos (MariaDB) y los archivos (logos y PDF de facturas), en la carpeta backups\.
  Se programa solo con instalar-tiempo.ps1 (diario, 3:00 a. m.). Tambien se puede correr a mano:

      powershell -ExecutionPolicy Bypass -File scripts\backup.ps1

  NO incluye .env.prod ni APP_KEY: guardalos aparte. Sin la misma APP_KEY, lo cifrado (p. ej. la clave de Accountisi) no se lee tras restaurar.
#>
[CmdletBinding()]
param(
    [string]$Proyecto = (Split-Path -Parent $PSScriptRoot),
    [string]$ComposeFile = 'docker-compose.prod.yml',
    [string]$EnvFile = '.env.prod',
    [int]$Conservar = 14
)
$ErrorActionPreference = 'Continue'
$dockerBin = 'C:\Program Files\Docker\Docker\resources\bin'
if (Test-Path $dockerBin) { $env:Path = $env:Path + ';' + $dockerBin }
Set-Location $Proyecto
$Compose = @('compose', '-f', $ComposeFile)
if ($EnvFile -and (Test-Path $EnvFile)) { $Compose += @('--env-file', $EnvFile) }
$destino = Join-Path $Proyecto 'backups'
New-Item -ItemType Directory -Force -Path $destino | Out-Null
$sello = Get-Date -Format 'yyyyMMdd-HHmmss'
$bd = Join-Path $destino "tiempo-db-$sello.sql.gz"
$archivos = Join-Path $destino "tiempo-archivos-$sello.tar.gz"

function Fallo($t) { Write-Host "[respaldo] ERROR: $t" -ForegroundColor Red; exit 1 }

Write-Host '[respaldo] base de datos...'
# Todo se hace DENTRO del contenedor y se copia como archivo: las redirecciones de PowerShell dañarian el contenido binario.
$cmdBd = 'mariadb-dump --single-transaction --routines --triggers --default-character-set=utf8mb4 -uroot -p$MARIADB_ROOT_PASSWORD $MARIADB_DATABASE | gzip > /tmp/respaldo-db.sql.gz'
& docker @Compose exec -T db sh -c $cmdBd
if ($LASTEXITCODE -ne 0) { Fallo 'no se pudo volcar la base de datos' }
& docker @Compose cp db:/tmp/respaldo-db.sql.gz $bd
if ($LASTEXITCODE -ne 0) { Fallo 'no se pudo copiar el respaldo de la base de datos' }
& docker @Compose exec -T db sh -c 'rm -f /tmp/respaldo-db.sql.gz' | Out-Null

Write-Host '[respaldo] archivos (logos y facturas en PDF)...'
& docker @Compose exec -T app sh -c 'tar -C /var/www/html/storage/app -czf /tmp/respaldo-archivos.tar.gz .'
if ($LASTEXITCODE -ne 0) { Fallo 'no se pudieron empacar los archivos' }
& docker @Compose cp app:/tmp/respaldo-archivos.tar.gz $archivos
if ($LASTEXITCODE -ne 0) { Fallo 'no se pudo copiar el respaldo de archivos' }
& docker @Compose exec -T app sh -c 'rm -f /tmp/respaldo-archivos.tar.gz' | Out-Null

# Un respaldo vacio o corrupto es peor que ninguno: se comprueba antes de decir que termino.
foreach ($f in $bd, $archivos) {
    if (-not (Test-Path $f) -or (Get-Item $f).Length -lt 100) { Fallo "$f esta vacio" }
}
$ok = & docker run --rm -v "${destino}:/b:ro" alpine sh -c "gzip -t /b/tiempo-db-$sello.sql.gz && gzip -t /b/tiempo-archivos-$sello.tar.gz && zcat /b/tiempo-db-$sello.sql.gz | grep -q 'CREATE TABLE' && echo bien"
if ($ok -ne 'bien') { Fallo 'el respaldo de la base no contiene tablas o esta danado' }

# Se conservan los ultimos N de cada tipo
foreach ($patron in 'tiempo-db-*.sql.gz', 'tiempo-archivos-*.tar.gz') {
    Get-ChildItem -Path $destino -Filter $patron | Sort-Object LastWriteTime -Descending | Select-Object -Skip $Conservar | Remove-Item -Force
}
Write-Host ("[respaldo] listo: {0} ({1} KB) y {2} ({3} KB)" -f (Split-Path -Leaf $bd), [math]::Round((Get-Item $bd).Length / 1KB), (Split-Path -Leaf $archivos), [math]::Round((Get-Item $archivos).Length / 1KB))
Write-Host '[respaldo] Copia la carpeta backups a otro lugar (S3, otro disco) y guarda aparte .env.prod (sobre todo APP_KEY).'
