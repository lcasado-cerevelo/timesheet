<#
  TIEMPO: instala TODO en un Windows (Docker, la aplicacion, HTTPS, la compania y el respaldo diario).

  Como se usa (en el servidor Windows, PowerShell COMO ADMINISTRADOR, con este archivo y tiempo-produccion.zip en la misma carpeta):

      powershell -ExecutionPolicy Bypass -File .\instalar-tiempo.ps1 -CorreoDueno tucorreo@cerevelo.com

  Solo revisar el servidor, sin cambiar nada:

      powershell -ExecutionPolicy Bypass -File .\instalar-tiempo.ps1 -SoloRevisar

  Si Windows pide reiniciar (la primera vez, al activar WSL2), reinicia y vuelve a ejecutar el MISMO comando: continua donde se quedo.
#>
[CmdletBinding()]
param(
    [string]$CorreoDueno,
    [string]$NombreDueno = 'Luis',
    [string]$NombreCompania = 'Cerevelo',
    [string]$Dominio = 'timesheet.cerevelo.com',
    [string]$Carpeta = 'C:\Tiempo',
    [string]$Zip = '',
    [switch]$SoloRevisar,
    [switch]$DetenerIIS      # permite parar y desactivar IIS si ocupa el puerto 80/443 (se caen TODOS los sitios que IIS sirva)
)

$ErrorActionPreference = 'Continue'      # docker escribe avisos por stderr; los errores reales se revisan con $LASTEXITCODE
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$Aqui = Split-Path -Parent $MyInvocation.MyCommand.Path
$Proyecto = Join-Path $Carpeta 'timesheet'
$Compose = @('compose', '-f', 'docker-compose.prod.yml', '--env-file', '.env.prod')
$Total = if ($SoloRevisar) { 3 } else { 9 }

function Paso($n, $texto) { Write-Host ''; Write-Host ("[{0}/{1}] {2}" -f $n, $Total, $texto) -ForegroundColor Cyan }
function Ok($t)    { Write-Host ('   OK   ' + $t) -ForegroundColor Green }
function Aviso($t) { Write-Host ('   OJO  ' + $t) -ForegroundColor Yellow }
function Info($t)  { Write-Host ('        ' + $t) }
function Falla($t) {
    Write-Host ''
    Write-Host ('   ERROR  ' + $t) -ForegroundColor Red
    Write-Host '   El instalador se detuvo. Corrige eso y vuelve a ejecutar el mismo comando.' -ForegroundColor Red
    try { Stop-Transcript | Out-Null } catch {}
    exit 1
}
function Docker { & docker @args }

# ---------------------------------------------------------------------------------------------
$Registro = Join-Path $env:TEMP 'instalar-tiempo.log'
try { Start-Transcript -Path $Registro -Force | Out-Null } catch {}
Write-Host ''
Write-Host 'TIEMPO - instalador para Windows' -ForegroundColor White
Write-Host ("Dominio: {0}   Carpeta: {1}" -f $Dominio, $Carpeta)

# ---- 1. Revisar el servidor ----------------------------------------------------------------
Paso 1 'Reviso el servidor'
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { Falla 'Abre PowerShell con clic derecho > "Ejecutar como administrador" y vuelve a correr el comando.' }
Ok 'Eres administrador'

$os = Get-CimInstance Win32_OperatingSystem
$build = [int]$os.BuildNumber
$esServidor = ($os.ProductType -ne 1)
Info ("{0} (compilacion {1})" -f $os.Caption, $build)
if ($build -lt 19041) { Falla 'Esta version de Windows es muy vieja. Docker con WSL2 necesita Windows 10 version 2004 o mas nueva, o Windows Server 2022.' }
Ok 'La version de Windows sirve'
if ($esServidor) { Aviso 'Es Windows Server. Docker Desktop no es oficial en Windows Server; si el paso de Docker falla, mira el aviso final.' }

$gb = [math]::Round($os.TotalVisibleMemorySize / 1MB, 1)
if ($gb -lt 3.5) { Falla ("Memoria: {0} GB. Se necesitan al menos 4 GB (construir la imagen pide memoria)." -f $gb) }
if ($gb -lt 6) { Aviso ("Memoria: {0} GB. Alcanza, pero justa; si la construccion falla por memoria, usa 8 GB." -f $gb) } else { Ok ("Memoria: {0} GB" -f $gb) }

$libre = [math]::Round((Get-PSDrive -Name ($env:SystemDrive.TrimEnd(':'))).Free / 1GB, 1)
if ($libre -lt 15) { Falla ("Disco libre: {0} GB. Se necesitan al menos 15 GB." -f $libre) }
Ok ("Disco libre: {0} GB" -f $libre)

$cs = Get-CimInstance Win32_ComputerSystem
$cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
if ($cpu.VirtualizationFirmwareEnabled -eq $false -and -not $cs.HypervisorPresent) {
    Falla 'La virtualizacion esta apagada en el BIOS. Activala (Intel VT-x / AMD-V) y reinicia.'
}
if ($cs.HypervisorPresent -and $cs.Model -match 'Virtual|HVM|KVM|VMware|Xen|Nitro|t3|t2|m5|c5') {
    Aviso 'Esto parece una maquina virtual (por ejemplo una instancia EC2).'
    Info 'Docker con contenedores de Linux necesita "virtualizacion anidada". Muchas instancias de AWS NO la permiten.'
    Info 'Si mas adelante Docker no arranca, es por esto: hace falta una instancia que la permita (o metal) o un servidor Linux.'
}

# ---- 2. Puertos 80 y 443 -------------------------------------------------------------------
Paso 2 'Reviso que los puertos 80 y 443 esten libres'
$ocupados = @(Get-NetTCPConnection -State Listen -LocalPort 80, 443 -ErrorAction SilentlyContinue)
if ($ocupados.Count -eq 0) { Ok 'Los puertos 80 y 443 estan libres' }
else {
    foreach ($c in $ocupados) {
        $p = Get-Process -Id $c.OwningProcess -ErrorAction SilentlyContinue
        Aviso ("Puerto {0} en uso por: {1} (PID {2})" -f $c.LocalPort, $(if ($p) { $p.ProcessName } else { 'sistema' }), $c.OwningProcess)
    }
    $iis = @('W3SVC', 'WAS' | ForEach-Object { Get-Service -Name $_ -ErrorAction SilentlyContinue } | Where-Object { $_ -and $_.Status -eq 'Running' })
    if ($iis.Count -gt 0) {
        Aviso 'IIS (el servidor web de Windows) esta corriendo y es quien ocupa el puerto 80/443.'
        try {
            Import-Module WebAdministration -ErrorAction Stop
            Info 'Sitios que sirve IIS y que DEJARIAN de funcionar si se detiene:'
            Get-Website | ForEach-Object { Info ("  - {0} [{1}]  {2}" -f $_.Name, $_.State, (($_.Bindings.Collection | ForEach-Object { $_.bindingInformation }) -join ', ')) }
        } catch { Info 'No pude listar los sitios de IIS (modulo WebAdministration no disponible).' }
        if (-not $SoloRevisar -and -not $DetenerIIS) {
            Write-Host ''
            Write-Host '   El instalador NO detiene IIS por su cuenta, porque se caerian los sitios de arriba.' -ForegroundColor Yellow
            Write-Host '   - Si no los necesitas: vuelve a ejecutar con  -DetenerIIS' -ForegroundColor Yellow
            Write-Host '   - Si IIS tiene que seguir: Tiempo necesita su propio puerto 80/443 (otra IP o otra maquina). Dime y lo adaptamos.' -ForegroundColor Yellow
            try { Stop-Transcript | Out-Null } catch {}
            exit 1
        }
    }
    if (-not $SoloRevisar) {
        foreach ($s in 'W3SVC', 'WAS') {
            $svc = Get-Service -Name $s -ErrorAction SilentlyContinue
            if ($svc -and $svc.Status -eq 'Running') {
                Info "Detengo y desactivo el servicio de IIS ($s)."
                Stop-Service -Name $s -Force -ErrorAction SilentlyContinue
                Set-Service -Name $s -StartupType Disabled -ErrorAction SilentlyContinue
            }
        }
        Start-Sleep -Seconds 3
        $quedan = @(Get-NetTCPConnection -State Listen -LocalPort 80, 443 -ErrorAction SilentlyContinue | Where-Object { (Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue).ProcessName -notin @('com.docker.backend', 'wslrelay', 'wslhost') })
        if ($quedan.Count -gt 0) {
            Info 'Quien tiene registrada la direccion en http.sys (Windows):'
            & netsh.exe http show urlacl 2>$null | Select-String -Pattern ':80/|:443/' -Context 1,0 | ForEach-Object { Info ($_.ToString().Trim()) }
            Falla 'Hay otro programa usando el puerto 80 o 443 (lista arriba). Detenlo o desactivalo y vuelve a ejecutar.'
        }
        Ok 'Puertos liberados'
    }
}

if ($SoloRevisar) {
    Paso 3 'Reviso la red'
}
else {
    Paso 3 'Abro el Firewall de Windows (80 y 443)'
    foreach ($r in @(@('Tiempo HTTP 80', 'TCP', 80), @('Tiempo HTTPS 443', 'TCP', 443), @('Tiempo HTTP3 443', 'UDP', 443))) {
        if (-not (Get-NetFirewallRule -DisplayName $r[0] -ErrorAction SilentlyContinue)) {
            New-NetFirewallRule -DisplayName $r[0] -Direction Inbound -Protocol $r[1] -LocalPort $r[2] -Action Allow -Profile Any | Out-Null
        }
    }
    Ok 'Reglas de entrada creadas'
    Info 'RECUERDA: en AWS tambien deben estar abiertos el 80 y el 443 en el grupo de seguridad de la maquina.'
}
try {
    $ipPublica = (Invoke-RestMethod -Uri 'https://checkip.amazonaws.com' -TimeoutSec 15).ToString().Trim()
    $dns = @(Resolve-DnsName -Name $Dominio -Type A -ErrorAction Stop | Where-Object { $_.Type -eq 'A' } | ForEach-Object { $_.IPAddress })
    if ($dns -contains $ipPublica) { Ok ("{0} apunta a esta maquina ({1})" -f $Dominio, $ipPublica) }
    else { Aviso ("{0} apunta a {1}, pero esta maquina sale a internet como {2}. Si no coinciden, el certificado no se podra emitir (en Cloudflare: registro A con la nube GRIS)." -f $Dominio, ($dns -join ', '), $ipPublica) }
}
catch { Aviso ("No pude comprobar el DNS de {0} ({1})." -f $Dominio, $_.Exception.Message) }

if ($SoloRevisar) { Write-Host ''; Write-Host 'Revision terminada. No se cambio nada.' -ForegroundColor Green; try { Stop-Transcript | Out-Null } catch {}; exit 0 }

# ---- 4. WSL2 -------------------------------------------------------------------------------
Paso 4 'Activo WSL2 (lo que usa Docker)'
$reinicio = $false
foreach ($f in 'Microsoft-Windows-Subsystem-Linux', 'VirtualMachinePlatform') {
    $est = Get-WindowsOptionalFeature -Online -FeatureName $f -ErrorAction SilentlyContinue
    if ($est -and $est.State -eq 'Enabled') { Ok "$f ya esta activo" }
    else {
        Info "Activando $f ..."
        $r = Enable-WindowsOptionalFeature -Online -FeatureName $f -All -NoRestart -ErrorAction SilentlyContinue
        if ($r -and $r.RestartNeeded) { $reinicio = $true }
    }
}
if ($reinicio) {
    Write-Host ''
    Write-Host 'Windows necesita REINICIAR para terminar de activar WSL2.' -ForegroundColor Yellow
    Write-Host 'Reinicia el servidor, entra de nuevo como administrador y vuelve a ejecutar el MISMO comando.' -ForegroundColor Yellow
    try { Stop-Transcript | Out-Null } catch {}
    exit 0
}
& wsl.exe --update 2>&1 | Out-Null
& wsl.exe --set-default-version 2 2>&1 | Out-Null
Ok 'WSL2 listo'

# ---- 5. Docker -----------------------------------------------------------------------------
Paso 5 'Instalo Docker Desktop'
$dockerBin = 'C:\Program Files\Docker\Docker\resources\bin'
if (Test-Path $dockerBin) { $env:Path = $env:Path + ';' + $dockerBin }
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    $instalador = Join-Path $env:TEMP 'DockerDesktopInstaller.exe'
    Info 'Descargando Docker Desktop (unos 600 MB; tarda unos minutos)...'
    try { Start-BitsTransfer -Source 'https://desktop.docker.com/win/main/amd64/Docker%20Desktop%20Installer.exe' -Destination $instalador }
    catch { Falla ("No pude descargar Docker Desktop: {0}" -f $_.Exception.Message) }
    Info 'Instalando (sin ventanas)...'
    $p = Start-Process -FilePath $instalador -ArgumentList 'install', '--quiet', '--accept-license', '--backend=wsl-2' -Wait -PassThru
    if ($p.ExitCode -ne 0) {
        Aviso ("El instalador de Docker Desktop termino con el codigo {0}." -f $p.ExitCode)
        if ($esServidor) { Info 'En Windows Server esto es comun: Docker Desktop no es oficial ahi. Ver el aviso final.' }
    }
    if (Test-Path $dockerBin) { $env:Path = $env:Path + ';' + $dockerBin }
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { Falla 'Docker no quedo instalado. Revisa el aviso final de este instalador.' }
    try { Add-LocalGroupMember -Group 'docker-users' -Member $env:USERNAME -ErrorAction Stop } catch {}
}
else { Ok 'Docker ya esta instalado' }

& docker info 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) {
    Info 'Arrancando Docker Desktop...'
    $exe = 'C:\Program Files\Docker\Docker\Docker Desktop.exe'
    if (Test-Path $exe) { Start-Process $exe }
    $fin = (Get-Date).AddMinutes(6)
    do { Start-Sleep -Seconds 8; & docker info 2>&1 | Out-Null } while ($LASTEXITCODE -ne 0 -and (Get-Date) -lt $fin)
    if ($LASTEXITCODE -ne 0) {
        Write-Host ''
        Write-Host '   Docker no arranco. Causas mas probables:' -ForegroundColor Red
        Write-Host '   1) Falta la virtualizacion anidada (muchas instancias de AWS no la permiten) o esta apagada en el BIOS.' -ForegroundColor Red
        Write-Host '   2) Acaba de activarse WSL2 y falta reiniciar.' -ForegroundColor Red
        Write-Host '   3) Windows Server: Docker Desktop no es compatible. Una instancia Linux pequena resuelve esto en 3 comandos.' -ForegroundColor Red
        Falla 'Docker Desktop no esta funcionando.'
    }
}
$motor = (& docker version --format '{{.Server.Os}}' 2>$null)
if ($motor -eq 'windows') {
    Info 'Cambiando Docker a contenedores de Linux...'
    & 'C:\Program Files\Docker\Docker\DockerCli.exe' -SwitchLinuxEngine 2>&1 | Out-Null
    Start-Sleep -Seconds 20
}
Ok 'Docker funcionando con contenedores de Linux'
Info 'Docker Desktop arranca al iniciar sesion. En un servidor: despues de REINICIAR, entra una vez por Escritorio remoto para que Docker (y la aplicacion) vuelvan a subir.'

# ---- 6. Desempaquetar ----------------------------------------------------------------------
Paso 6 ("Preparo los archivos en {0}" -f $Carpeta)
if (-not (Test-Path (Join-Path $Proyecto 'docker-compose.prod.yml'))) {
    if (-not $Zip) {
        foreach ($cand in @((Join-Path $Aqui 'tiempo-produccion.zip'), (Join-Path $env:USERPROFILE 'Downloads\tiempo-produccion.zip'), (Join-Path $env:USERPROFILE 'Desktop\tiempo-produccion.zip'))) {
            if (Test-Path $cand) { $Zip = $cand; break }
        }
    }
    if (-not $Zip -or -not (Test-Path $Zip)) { Falla 'No encuentro tiempo-produccion.zip. Ponlo en la misma carpeta que este archivo (o usa -Zip ruta).' }
    New-Item -ItemType Directory -Force -Path $Carpeta | Out-Null
    Expand-Archive -Path $Zip -DestinationPath $Carpeta -Force
    Ok ("Desempaquetado en {0}" -f $Proyecto)
}
else { Ok 'Los archivos ya estaban; no se tocan (tu .env.prod se conserva).' }
if (-not (Test-Path (Join-Path $Proyecto '.env.prod'))) { Falla 'Falta .env.prod en el zip. Genera el zip de nuevo.' }
Set-Location $Proyecto

# ---- 7. Levantar la aplicacion -------------------------------------------------------------
Paso 7 'Construyo y levanto la aplicacion (la primera vez tarda varios minutos)'
& docker @Compose up -d --build 2>&1 | ForEach-Object { Write-Host ('        ' + $_) }
if ($LASTEXITCODE -ne 0) { Falla 'docker compose no pudo levantar la aplicacion (mira las lineas de arriba).' }
$fin = (Get-Date).AddMinutes(6)
do { Start-Sleep -Seconds 6; & docker @Compose exec -T app php -r 'echo "ok";' 2>&1 | Out-Null } while ($LASTEXITCODE -ne 0 -and (Get-Date) -lt $fin)
if ($LASTEXITCODE -ne 0) { Falla 'La aplicacion no arranco. Mira: docker compose -f docker-compose.prod.yml --env-file .env.prod logs app' }
Ok 'La aplicacion esta corriendo y la base de datos aplicada'

Info 'Espero el certificado HTTPS (Let''s Encrypt; hasta 3 minutos)...'
$fin = (Get-Date).AddMinutes(3); $https = $false
do {
    Start-Sleep -Seconds 10
    try { $resp = Invoke-WebRequest -Uri ("https://{0}/up" -f $Dominio) -UseBasicParsing -TimeoutSec 15; if ($resp.StatusCode -eq 200) { $https = $true } } catch {}
} while (-not $https -and (Get-Date) -lt $fin)
if ($https) { Ok ("https://{0} responde con certificado valido" -f $Dominio) }
else {
    Aviso ("https://{0} todavia no responde. Casi siempre es: (a) el DNS no apunta aqui o esta con nube NARANJA en Cloudflare, o (b) los puertos 80/443 no estan abiertos en AWS." -f $Dominio)
    Info 'Cuando lo corrijas, el certificado se pide solo. Para ver el progreso: docker compose -f docker-compose.prod.yml --env-file .env.prod logs caddy'
}

# ---- 8. Crear la compania ------------------------------------------------------------------
Paso 8 'Creo la compania y el usuario dueño'
if (-not $CorreoDueno) { $CorreoDueno = Read-Host 'Correo del dueño (con el que vas a entrar)' }
if ($CorreoDueno -notmatch '^[^@\s]+@[^@\s]+\.[^@\s]+$') { Falla 'El correo del dueño no es valido.' }
try { Stop-Transcript | Out-Null } catch {}     # para que la contraseña NO quede en el registro
$salida = & docker @Compose exec -T app php artisan tiempo:instalar $NombreCompania $CorreoDueno "--nombre=$NombreDueno" 2>&1 | Out-String
$codigo = $LASTEXITCODE
if ($codigo -eq 0) {
    Write-Host ''
    Write-Host '   ==================================================================' -ForegroundColor Green
    Write-Host $salida
    Write-Host '   ==================================================================' -ForegroundColor Green
    Write-Host '   COPIA ESA CONTRASEÑA AHORA: no se vuelve a mostrar. Cambiala en "My account" al entrar.' -ForegroundColor Yellow
    [void](Read-Host '   Cuando la hayas copiado, presiona Enter')
}
elseif ($salida -match 'ya existe|already') { Aviso 'La compania o ese correo ya existian: no se creo de nuevo.' }
else { Aviso ("No se pudo crear la compania: {0}" -f $salida.Trim()) }
try { Start-Transcript -Path $Registro -Append | Out-Null } catch {}

# ---- 9. Respaldo diario --------------------------------------------------------------------
Paso 9 'Programo el respaldo diario (3:00 a. m.)'
$script = Join-Path $Proyecto 'scripts\backup.ps1'
if (Test-Path $script) {
    $accion = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ("-NoProfile -ExecutionPolicy Bypass -File `"{0}`"" -f $script) -WorkingDirectory $Proyecto
    $disparador = New-ScheduledTaskTrigger -Daily -At 3am
    $principal = New-ScheduledTaskPrincipal -UserId ("{0}\{1}" -f $env:USERDOMAIN, $env:USERNAME) -LogonType Interactive -RunLevel Highest
    Register-ScheduledTask -TaskName 'Tiempo respaldo diario' -Action $accion -Trigger $disparador -Principal $principal -Force | Out-Null
    Ok ("Respaldo diario programado. Se guarda en {0}\backups (los ultimos 14)." -f $Proyecto)
    Info 'IMPORTANTE: copia esa carpeta backups a otro lugar (S3, otro disco) y guarda aparte el archivo .env.prod, sobre todo APP_KEY.'
}
else { Aviso 'No encontre scripts\backup.ps1; el respaldo no se programo.' }

# ---- Fin -----------------------------------------------------------------------------------
Write-Host ''
Write-Host 'LISTO.' -ForegroundColor Green
Write-Host ("  Entra a  https://{0}/app/" -f $Dominio)
Write-Host '  Despues, en Settings > Rules pon el Domain, en Branding tus colores y logo, y en People invita a la gente.'
Write-Host ("  Archivos: {0}   Registro de este instalador: {1}" -f $Proyecto, $Registro)
try { Stop-Transcript | Out-Null } catch {}
