<?php
/**
 * Cerevelo Timesheet - installation wizard (one file, no dependencies).
 *
 * Upload this file and timesheet-app.zip to the folder that the website serves
 * (the domain's document root, or a folder inside public_html such as public_html/timesheet),
 * open instalar.php in the browser and follow the steps. When it finishes it deletes itself.
 */

declare(strict_types=1);

@set_time_limit(0);
@ini_set('memory_limit', '512M');
@ini_set('display_errors', '0');
error_reporting(E_ALL);
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

const ZIP_NAME = 'timesheet-app.zip';
const MARKER = 'TIMESHEET-INSTALLED';
const MIN_PHP = '8.3.0';

$WEB = str_replace('\\', '/', __DIR__);

/* ---------------------------------------------------------------- language */
if (isset($_GET['lang']) && in_array($_GET['lang'], ['es', 'en'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}
if (empty($_SESSION['lang'])) {
    $_SESSION['lang'] = str_starts_with(strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'es')), 'en') ? 'en' : 'es';
}
function t(string $es, string $en): string
{
    return $_SESSION['lang'] === 'en' ? $en : $es;
}
function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$cfg = &$_SESSION['cfg'];
$cfg = $cfg ?? [];

/* ---------------------------------------------------------------- helpers */
function detectPaths(string $web): array
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $url = ($https ? 'https' : 'http') . '://' . $host . $dir;
    $p = strpos($web, '/public_html');
    $home = $p !== false ? substr($web, 0, $p) : dirname($web);
    return ['url' => $url, 'host' => preg_replace('/:\d+$/', '', $host), 'sub' => $dir !== '', 'dir' => $dir,
        'app' => $home . '/timesheet-app', 'https' => $https];
}

function requirements(string $web): array
{
    $rows = [];
    $add = function (string $name, bool $ok, string $detail, bool $blocking = true) use (&$rows) {
        $rows[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'blocking' => $blocking];
    };
    $add('PHP ' . MIN_PHP . '+', version_compare(PHP_VERSION, MIN_PHP, '>='), t('Versión actual: ', 'Current version: ') . PHP_VERSION);
    foreach (['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'zip', 'curl', 'dom'] as $e) {
        $add(t('Extensión ', 'Extension ') . $e, extension_loaded($e), extension_loaded($e) ? t('Activa', 'Enabled') : t('Falta: actívala en "Select PHP Version" del cPanel', 'Missing: enable it in cPanel "Select PHP Version"'));
    }
    foreach (['gd', 'intl', 'bcmath', 'exif'] as $e) {
        $add(t('Extensión ', 'Extension ') . $e . t(' (recomendada)', ' (recommended)'), extension_loaded($e),
            extension_loaded($e) ? t('Activa', 'Enabled') : t('Conviene activarla (PDF, Excel y fechas)', 'Better enable it (PDF, Excel and dates)'), false);
    }
    $add(t('Archivo ', 'File ') . ZIP_NAME, is_file($web . '/' . ZIP_NAME), is_file($web . '/' . ZIP_NAME) ? t('Encontrado junto al instalador', 'Found next to the installer') : t('Súbelo a esta misma carpeta', 'Upload it to this same folder'));
    $add(t('Carpeta escribible', 'Writable folder'), is_writable($web), $web);
    return $rows;
}

function envq(string $v): string
{
    return preg_match('/^[A-Za-z0-9_.:\/@+\-]*$/', $v) ? $v : '"' . addcslashes($v, "\"\\$") . '"';
}

function dbConnect(array $c): PDO
{
    $dsn = 'mysql:host=' . $c['db_host'] . ';port=' . (int) $c['db_port'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4';
    return new PDO($dsn, $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
}

function friendlyDbError(Throwable $e): string
{
    $m = $e->getMessage();
    if (str_contains($m, '1045')) {
        return t('El servidor rechazó el usuario o la contraseña. Revisa los dos y que el usuario esté agregado a la base con todos los privilegios (cPanel → MySQL Databases → Add User To Database).',
            'The server rejected the user or password. Check both, and that the user is added to the database with all privileges (cPanel → MySQL Databases → Add User To Database).');
    }
    if (str_contains($m, '1049')) {
        return t('Esa base de datos no existe. Revisa el nombre exacto en cPanel → MySQL Databases.', 'That database does not exist. Check the exact name in cPanel → MySQL Databases.');
    }
    if (str_contains($m, '2002') || str_contains($m, '2006')) {
        return t('No se pudo llegar al servidor de base de datos. En hosting compartido casi siempre es "localhost".', 'The database server could not be reached. On shared hosting it is almost always "localhost".');
    }
    return $m;
}

/** Error text if the application folder cannot be used, or null when it is fine. */
function appDirError(string $dir, string $web, bool $mustBeFresh = true): ?string
{
    if ($dir === '' || $dir[0] !== '/') {
        return t('La carpeta de la aplicación debe ser una ruta completa, como /home/usuario/timesheet-app.', 'The application folder must be a full path, like /home/user/timesheet-app.');
    }
    if (str_starts_with($dir . '/', $web . '/')) {
        return t('La aplicación NO puede quedar dentro de la carpeta pública (se podrían descargar sus claves). Elige una carpeta fuera.', 'The application must NOT be inside the public folder (its keys could be downloaded). Choose a folder outside.');
    }
    if (!$mustBeFresh) {
        return null;
    }
    if (is_file($dir . '/.env')) {
        return t('Ya hay una instalación en esa carpeta. Elige otra o bórrala.', 'There is already an installation in that folder. Choose another one or delete it.');
    }
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return t('No se pudo crear esa carpeta. Revisa la ruta y los permisos.', 'That folder could not be created. Check the path and permissions.');
    }
    if (!rrmdirEmpty($dir) && !is_file($dir . '/artisan')) {
        return t('Esa carpeta ya tiene archivos. Elige una vacía.', 'That folder already has files. Choose an empty one.');
    }
    return null;
}

function rrmdirEmpty(string $dir): bool
{
    return !is_dir($dir) || count(array_diff(scandir($dir) ?: [], ['.', '..'])) === 0;
}

function json(array $d, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function phpBinaryHint(): string
{
    $v = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
    foreach (["/opt/alt/php$v/usr/bin/php", "/opt/cpanel/ea-php$v/root/usr/bin/php", '/usr/local/bin/php', '/usr/bin/php'] as $p) {
        if (@is_file($p)) {
            return $p;
        }
    }
    return 'php';
}

/* ---------------------------------------------------------------- already installed? */
if (is_file($WEB . '/index.php') && str_contains((string) @file_get_contents($WEB . '/index.php'), MARKER)) {
    @unlink(__FILE__);
    page(t('Ya está instalado', 'Already installed'), '<p>' . t('Esta aplicación ya está instalada y este instalador se bloqueó.', 'This application is already installed and the installer is locked.') . '</p>');
}

/* ---------------------------------------------------------------- ajax: extract + finish */
if (isset($_GET['do'])) {
    if (($_SERVER['HTTP_X_CSRF'] ?? '') !== $_SESSION['csrf']) {
        json(['ok' => false, 'error' => t('Sesión inválida. Recarga la página.', 'Invalid session. Reload the page.')], 403);
    }
    try {
        if ($_GET['do'] === 'extract') {
            doExtract($WEB, $cfg);
        }
        if ($_GET['do'] === 'finish') {
            doFinish($WEB, $cfg);
        }
    } catch (Throwable $e) {
        json(['ok' => false, 'error' => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'], 500);
    }
    json(['ok' => false, 'error' => 'Unknown action'], 400);
}

function doExtract(string $web, array $cfg): never
{
    if (empty($cfg['app_dir']) || empty($cfg['db_name']) || empty($cfg['owner_email'])) {
        json(['ok' => false, 'error' => t('Faltan datos de los pasos anteriores.', 'Data from the previous steps is missing.')], 400);
    }
    if ($msg = appDirError(rtrim($cfg['app_dir'], '/'), $web, false)) {
        json(['ok' => false, 'error' => $msg], 400);
    }
    $zipPath = $web . '/' . ZIP_NAME;
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        json(['ok' => false, 'error' => t('No se pudo abrir ', 'Could not open ') . ZIP_NAME], 500);
    }
    $app = rtrim($cfg['app_dir'], '/');
    $from = max(0, (int) ($_GET['from'] ?? 0));
    $total = $zip->numFiles;
    $to = min($total, $from + 600);
    for ($i = $from; $i < $to; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (str_contains($name, '..')) {
            continue;
        }
        if (str_starts_with($name, 'app/')) {
            $dest = $app . '/' . substr($name, 4);
        } elseif (str_starts_with($name, 'public/')) {
            $dest = $web . '/' . substr($name, 7);
        } else {
            continue;
        }
        if (str_ends_with($name, '/')) {
            if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
                json(['ok' => false, 'error' => t('No se pudo crear la carpeta ', 'Could not create folder ') . $dest], 500);
            }
            continue;
        }
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            json(['ok' => false, 'error' => t('No se pudo crear la carpeta ', 'Could not create folder ') . $dir], 500);
        }
        $in = $zip->getStream($name);
        $out = @fopen($dest, 'wb');
        if (!$in || !$out) {
            json(['ok' => false, 'error' => t('No se pudo escribir ', 'Could not write ') . $dest], 500);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }
    $zip->close();
    json(['ok' => true, 'next' => $to, 'total' => $total, 'done' => $to >= $total]);
}

function doFinish(string $web, array $c): never
{
    $log = [];
    $app = rtrim($c['app_dir'], '/');
    if ($msg = appDirError($app, $web, false)) {
        json(['ok' => false, 'error' => $msg], 400);
    }
    if (!is_file($app . '/artisan') || !is_file($app . '/vendor/autoload.php')) {
        json(['ok' => false, 'error' => t('La aplicación no se descomprimió completa.', 'The application was not fully extracted.')], 500);
    }
    foreach (['storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $d) {
        if (!is_dir("$app/$d")) {
            @mkdir("$app/$d", 0775, true);
        }
        @chmod("$app/$d", 0775);
    }
    @chmod("$app/storage", 0775);
    @chmod("$app/bootstrap/cache", 0775);
    $log[] = t('Carpetas de trabajo listas.', 'Working folders ready.');

    /* .env */
    $mail = !empty($c['mail_host']) && !empty($c['mail_from']);
    $env = [
        'APP_NAME' => $c['company'], 'APP_ENV' => 'production', 'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)), 'APP_DEBUG' => 'false',
        'APP_URL' => rtrim($c['app_url'], '/'), 'APP_LOCALE' => $c['locale'], 'APP_FALLBACK_LOCALE' => 'en',
        'LOG_CHANNEL' => 'daily', 'LOG_LEVEL' => 'warning',
        'SESSION_DRIVER' => 'file', 'SESSION_SECURE_COOKIE' => str_starts_with($c['app_url'], 'https://') ? 'true' : 'false',
        'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'BCRYPT_ROUNDS' => '12', 'TRUSTED_PROXIES' => '',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => $c['db_host'], 'DB_PORT' => (string) $c['db_port'], 'DB_DATABASE' => $c['db_name'],
        'DB_USERNAME' => $c['db_user'], 'DB_PASSWORD' => $c['db_pass'],
        'MAIL_MAILER' => $mail ? 'smtp' : 'log', 'MAIL_HOST' => $c['mail_host'] ?? '', 'MAIL_PORT' => (string) ($c['mail_port'] ?? 587),
        'MAIL_SCHEME' => 'null', 'MAIL_USERNAME' => $c['mail_user'] ?? '', 'MAIL_PASSWORD' => $c['mail_pass'] ?? '',
        'MAIL_FROM_ADDRESS' => $c['mail_from'] ?? ('no-reply@' . ($c['host'] ?? 'localhost')), 'MAIL_FROM_NAME' => $c['company'],
        'TURNSTILE_SITE_KEY' => $c['ts_site'] ?? '', 'TURNSTILE_SECRET' => $c['ts_secret'] ?? '',
        'CRON_KEY' => bin2hex(random_bytes(20)),
    ];
    $lines = [];
    foreach ($env as $k => $v) {
        $lines[] = $k . '=' . envq((string) $v);
    }
    if (file_put_contents($app . '/.env', implode("\n", $lines) . "\n") === false) {
        json(['ok' => false, 'error' => t('No se pudo escribir el archivo .env', 'Could not write the .env file')], 500);
    }
    @chmod($app . '/.env', 0600);
    $log[] = t('Archivo .env creado con una clave de aplicación nueva.', '.env file created with a new application key.');

    /* front controller + .htaccess in the public folder */
    $front = "<?php\n// " . MARKER . "\n"
        . "use Illuminate\\Http\\Request;\n\n"
        . "define('LARAVEL_START', microtime(true));\n"
        . "\$base = " . var_export($app, true) . ";\n"
        . "if (file_exists(\$m = \$base.'/storage/framework/maintenance.php')) { require \$m; }\n"
        . "require \$base.'/vendor/autoload.php';\n"
        . "\$app = require_once \$base.'/bootstrap/app.php';\n"
        . "\$app->usePublicPath(__DIR__);\n"
        . "\$app->handleRequest(Request::capture());\n";
    $htaccessOk = is_file($web . '/.htaccess');
    if (!$htaccessOk) {
        file_put_contents($web . '/.htaccess', "<IfModule mod_rewrite.c>\nOptions -MultiViews -Indexes\nRewriteEngine On\n"
            . "RewriteCond %{HTTP:Authorization} .\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
            . "RewriteCond %{REQUEST_FILENAME} !-d\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^ index.php [L]\n</IfModule>\n");
    }

    /* boot the application and run the commands in this same process */
    require $app . '/vendor/autoload.php';
    $laravel = require $app . '/bootstrap/app.php';
    $laravel->usePublicPath($web);
    $kernel = $laravel->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $run = function (string $cmd, array $args = []) use ($kernel, &$log) {
        $code = $kernel->call($cmd, $args);
        $out = trim((string) $kernel->output());
        return [$code, $out];
    };

    [$code, $out] = $run('migrate', ['--force' => true]);
    if ($code !== 0) {
        json(['ok' => false, 'error' => t('Falló la creación de las tablas: ', 'Creating the tables failed: ') . substr($out, -800)], 500);
    }
    $log[] = t('Tablas de la base de datos creadas.', 'Database tables created.');

    [$code, $out] = $run('tiempo:instalar', ['compania' => $c['company'], 'correo' => $c['owner_email'], '--nombre' => $c['owner_name'], '--clave' => $c['owner_pass']]);
    if ($code !== 0) {
        json(['ok' => false, 'error' => t('No se pudo crear la compañía: ', 'Could not create the company: ') . substr($out, -800)], 500);
    }
    $log[] = t('Compañía y dueño creados.', 'Company and owner created.');

    try {
        // El dominio de la compañía solo se guarda si es un nombre de sitio real (no localhost ni una IP): la pantalla de reglas lo valida.
        $host = strtolower((string) $c['host']);
        if (str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP) && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            Illuminate\Support\Facades\DB::table('companies')->orderBy('id')->limit(1)->update(['domain' => $host]);
        }
    } catch (Throwable $e) {
        $log[] = t('Aviso: no se pudo guardar el dominio de la compañía (se puede poner en Settings → Rules).', 'Notice: the company domain could not be saved (set it in Settings → Rules).');
    }

    $warn = [];
    try {
        $code = $kernel->call('storage:link');
        $log[] = t('Enlace de archivos (logos y PDF) creado.', 'Files link (logos and PDFs) created.');
    } catch (Throwable $e) {
        $warn[] = t('No se pudo crear el enlace "storage" (symlink bloqueado). Los logos subidos no se verán hasta crearlo.', 'The "storage" link could not be created (symlink blocked). Uploaded logos will not show until it is created.');
    }
    foreach (['config:cache', 'route:cache', 'view:cache'] as $cmd) {
        try {
            $kernel->call($cmd);
        } catch (Throwable $e) {
            $warn[] = $cmd . ': ' . $e->getMessage();
        }
    }
    $log[] = t('Cachés listas.', 'Caches ready.');

    /* lock */
    @file_put_contents($app . '/storage/installed.lock', date('c'));
    file_put_contents($web . '/index.php', $front);
    @unlink($web . '/' . ZIP_NAME);
    $deleted = @unlink(__FILE__);
    $log[] = $deleted ? t('Instalador borrado.', 'Installer deleted.') : t('Instalador bloqueado (bórralo a mano de esta carpeta).', 'Installer locked (delete it by hand from this folder).');

    $php = phpBinaryHint();
    json(['ok' => true, 'log' => $log, 'warn' => $warn, 'login' => rtrim($c['app_url'], '/') . '/app/',
        'cron' => 'cd ' . $app . ' && ' . $php . ' artisan schedule:run >/dev/null 2>&1']);
}

/* ---------------------------------------------------------------- page layout */
function page(string $title, string $body, int $step = 0): never
{
    $steps = [t('Requisitos', 'Requirements'), t('Ubicación', 'Location'), t('Base de datos', 'Database'), t('Compañía', 'Company'), t('Correo', 'Email'), t('Instalar', 'Install')];
    $nav = '';
    foreach ($steps as $i => $s) {
        $n = $i + 1;
        $nav .= '<li class="' . ($n === $step ? 'on' : ($n < $step ? 'done' : '')) . '"><span>' . $n . '</span>' . h($s) . '</li>';
    }
    $other = $_SESSION['lang'] === 'es' ? 'en' : 'es';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="' . $_SESSION['lang'] . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . h($title) . ' · Cerevelo Timesheet</title><style>'
        . ':root{--p:#04297C;--a:#087A85;--bg:#f3f5f8;--tx:#1b2430;--mu:#5b6675;--ok:#0b7a3b;--er:#b3261e}'
        . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--tx);font:16px/1.5 system-ui,Segoe UI,Roboto,sans-serif}'
        . 'header{background:var(--p);color:#fff;padding:16px 24px;display:flex;justify-content:space-between;align-items:center}'
        . 'header b{font-size:18px}header a{color:#fff;opacity:.85;text-decoration:none;font-size:14px;border:1px solid rgba(255,255,255,.4);padding:3px 10px;border-radius:6px}'
        . 'main{max-width:760px;margin:24px auto;padding:0 16px}ol{list-style:none;display:flex;gap:6px;padding:0;margin:0 0 18px;flex-wrap:wrap}'
        . 'ol li{font-size:13px;color:var(--mu);display:flex;align-items:center;gap:6px;margin-right:10px}ol li span{width:22px;height:22px;border-radius:50%;background:#d6dbe3;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px}'
        . 'ol li.on{color:var(--p);font-weight:600}ol li.on span{background:var(--p)}ol li.done span{background:var(--a)}'
        . '.card{background:#fff;border:1px solid #dfe4ec;border-radius:12px;padding:22px}h1{font-size:22px;margin:0 0 6px}p.sub{color:var(--mu);margin:0 0 18px}'
        . 'label{display:block;font-weight:600;font-size:14px;margin:14px 0 4px}small{display:block;color:var(--mu);margin-top:3px;font-size:13px}'
        . 'input,select{width:100%;padding:10px 12px;border:1px solid #c5ccd8;border-radius:8px;font:inherit;background:#fff}input:focus,select:focus{outline:2px solid var(--a);border-color:var(--a)}'
        . '.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:560px){.row{grid-template-columns:1fr}}'
        . '.btns{display:flex;justify-content:space-between;gap:10px;margin-top:22px}.btn{background:var(--p);color:#fff;border:0;border-radius:8px;padding:11px 20px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}'
        . '.btn.ghost{background:#fff;color:var(--p);border:1px solid var(--p)}.btn[disabled]{opacity:.45;cursor:not-allowed}'
        . '.err{background:#fdecea;border-left:4px solid var(--er);padding:10px 14px;border-radius:6px;margin-bottom:14px}.okb{background:#e8f5ec;border-left:4px solid var(--ok);padding:10px 14px;border-radius:6px;margin-bottom:14px}'
        . 'table{width:100%;border-collapse:collapse;font-size:14px}td{padding:7px 6px;border-bottom:1px solid #eef0f4;vertical-align:top}td:first-child{width:30px;font-size:18px}'
        . '.good{color:var(--ok)}.bad{color:var(--er)}.warn{color:#9a6700}code,pre{background:#eef1f6;border-radius:6px;padding:2px 6px;font-size:13px;word-break:break-all}pre{padding:12px;white-space:pre-wrap}'
        . '.bar{height:12px;background:#e3e8f0;border-radius:6px;overflow:hidden;margin:14px 0}.bar i{display:block;height:100%;width:0;background:var(--a);transition:width .2s}'
        . '</style></head><body><header><b>Cerevelo Timesheet</b><a href="?lang=' . $other . '">' . ($other === 'en' ? 'English' : 'Español') . '</a></header><main>'
        . ($step ? '<ol>' . $nav . '</ol>' : '') . '<div class="card"><h1>' . h($title) . '</h1>' . $body . '</div></main></body></html>';
    exit;
}

function field(string $name, string $label, string $val = '', string $type = 'text', string $hint = '', bool $req = true): string
{
    return '<label for="f_' . $name . '">' . h($label) . '</label><input id="f_' . $name . '" name="' . $name . '" type="' . $type . '" value="' . h($val) . '"'
        . ($req ? ' required' : '') . ($type === 'password' ? ' autocomplete="new-password"' : ' autocomplete="off"') . '>' . ($hint ? '<small>' . h($hint) . '</small>' : '');
}
function form(string $inner, int $back, string $next): string
{
    return '<form method="post"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">' . $inner
        . '<div class="btns">' . ($back ? '<a class="btn ghost" href="?s=' . $back . '">' . t('Atrás', 'Back') . '</a>' : '<span></span>')
        . '<button class="btn" type="submit">' . h($next) . '</button></div></form>';
}

/* ---------------------------------------------------------------- POST handlers */
$step = max(1, min(6, (int) ($_GET['s'] ?? 1)));
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $err = t('Sesión inválida. Vuelve a intentarlo.', 'Invalid session. Try again.');
    } else {
        $p = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $_POST);
        if ($step === 2) {
            $url = rtrim((string) ($p['app_url'] ?? ''), '/');
            $dir = rtrim(str_replace('\\', '/', (string) ($p['app_dir'] ?? '')), '/');
            if (!preg_match('~^https?://[^/\s]+(/[^\s?#]*)?$~', $url)) {
                $err = t('La dirección debe empezar con https:// (o http://) y no llevar espacios.', 'The address must start with https:// (or http://) and have no spaces.');
            } elseif ($msg = appDirError($dir, $WEB)) {
                $err = $msg;
            } else {
                $d = detectPaths($WEB);
                $cfg['app_url'] = $url;
                $cfg['app_dir'] = $dir;
                $cfg['host'] = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: $d['host']));
                header('Location: ?s=3');
                exit;
            }
        } elseif ($step === 3) {
            $cfg = array_merge($cfg, ['db_host' => $p['db_host'] ?? 'localhost', 'db_port' => (int) ($p['db_port'] ?? 3306) ?: 3306, 'db_name' => $p['db_name'] ?? '',
                'db_user' => $p['db_user'] ?? '', 'db_pass' => (string) ($_POST['db_pass'] ?? '')]);
            try {
                $pdo = dbConnect($cfg);
                $n = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
                if ($n > 0) {
                    $err = t('La base de datos ya tiene tablas. Usa una base vacía (o bórrales las tablas en phpMyAdmin).', 'The database already has tables. Use an empty database (or drop its tables in phpMyAdmin).');
                } else {
                    header('Location: ?s=4');
                    exit;
                }
            } catch (Throwable $e) {
                $err = friendlyDbError($e);
            }
        } elseif ($step === 4) {
            $cfg = array_merge($cfg, ['company' => $p['company'] ?? '', 'owner_name' => $p['owner_name'] ?? '', 'owner_email' => $p['owner_email'] ?? '',
                'owner_pass' => (string) ($_POST['owner_pass'] ?? ''), 'locale' => ($p['locale'] ?? 'es') === 'en' ? 'en' : 'es']);
            if ($cfg['company'] === '' || $cfg['owner_name'] === '') {
                $err = t('Escribe el nombre de la compañía y el tuyo.', 'Enter the company name and yours.');
            } elseif (!filter_var($cfg['owner_email'], FILTER_VALIDATE_EMAIL)) {
                $err = t('El correo no es válido.', 'The email is not valid.');
            } elseif (mb_strlen($cfg['owner_pass']) < 10) {
                $err = t('La contraseña debe tener al menos 10 caracteres.', 'The password must be at least 10 characters.');
            } elseif ($cfg['owner_pass'] !== (string) ($_POST['owner_pass2'] ?? '')) {
                $err = t('Las dos contraseñas no coinciden.', 'The two passwords do not match.');
            } else {
                header('Location: ?s=5');
                exit;
            }
        } elseif ($step === 5) {
            $cfg = array_merge($cfg, ['mail_host' => $p['mail_host'] ?? '', 'mail_port' => (int) ($p['mail_port'] ?? 587) ?: 587, 'mail_user' => $p['mail_user'] ?? '',
                'mail_pass' => (string) ($_POST['mail_pass'] ?? ''), 'mail_from' => $p['mail_from'] ?? '', 'ts_site' => $p['ts_site'] ?? '', 'ts_secret' => $p['ts_secret'] ?? '']);
            if ($cfg['mail_from'] !== '' && !filter_var($cfg['mail_from'], FILTER_VALIDATE_EMAIL)) {
                $err = t('El correo remitente no es válido.', 'The sender email is not valid.');
            } elseif (($cfg['ts_site'] === '') !== ($cfg['ts_secret'] === '')) {
                $err = t('Turnstile necesita las dos claves (o ninguna).', 'Turnstile needs both keys (or none).');
            } else {
                header('Location: ?s=6');
                exit;
            }
        }
    }
}

/* ---------------------------------------------------------------- pages */
$d = detectPaths($WEB);
if (!$err && !empty($_SESSION['flash'])) {
    $err = (string) $_SESSION['flash'];
    unset($_SESSION['flash']);
}
$e = $err ? '<div class="err">' . h($err) . '</div>' : '';

if ($step === 1) {
    $rows = requirements($WEB);
    $block = false;
    $html = '<table>';
    foreach ($rows as $r) {
        $mark = $r['ok'] ? '<span class="good">✔</span>' : ($r['blocking'] ? '<span class="bad">✖</span>' : '<span class="warn">!</span>');
        $block = $block || (!$r['ok'] && $r['blocking']);
        $html .= '<tr><td>' . $mark . '</td><td><b>' . h($r['name']) . '</b><br><span style="color:var(--mu)">' . h($r['detail']) . '</span></td></tr>';
    }
    $html .= '</table>';
    $mode = $d['sub'] ? t('en la carpeta ', 'in the folder ') . '<code>' . h($d['dir']) . '</code>' : t('en la raíz del dominio', 'at the domain root');
    $lead = '<p class="sub">' . t('Este asistente instala la aplicación en unos minutos. Se detectó que estás instalando ', 'This wizard installs the application in a few minutes. It detected you are installing ') . $mode . '.</p>';
    $btn = $block ? '<button class="btn" disabled>' . t('Arregla lo marcado con ✖', 'Fix the items marked ✖') . '</button>'
        : '<a class="btn" href="?s=2">' . t('Continuar', 'Continue') . '</a>';
    page(t('Bienvenido', 'Welcome'), $lead . $html . '<div class="btns"><a class="btn ghost" href="?s=1">' . t('Revisar de nuevo', 'Check again') . '</a>' . $btn . '</div>', 1);
}

if ($step === 2) {
    $body = '<p class="sub">' . t('Dónde vivirá la aplicación.', 'Where the application will live.') . '</p>' . $e
        . field('app_url', t('Dirección del sitio', 'Site address'), $cfg['app_url'] ?? $d['url'], 'text', t('Sin la parte /app/. Si instalaste en una carpeta, incluye el nombre de la carpeta (por ejemplo https://tudominio.com/timesheet).', 'Without the /app/ part. If you installed in a folder, include the folder name (for example https://yourdomain.com/timesheet).'))
        . field('app_dir', t('Carpeta de la aplicación (fuera de la carpeta pública)', 'Application folder (outside the public folder)'), $cfg['app_dir'] ?? $d['app'], 'text',
            t('Ahí se guardan el código y las claves. Debe estar FUERA de ', 'The code and keys are stored there. It must be OUTSIDE of ') . $WEB);
    page(t('Ubicación', 'Location'), form($body, 1, t('Continuar', 'Continue')), 2);
}

if ($step === 3) {
    $body = '<p class="sub">' . t('Los datos de la base de datos MySQL/MariaDB. Créala antes en cPanel → MySQL Databases y agrega el usuario a la base con todos los privilegios.', 'The MySQL/MariaDB database details. Create it first in cPanel → MySQL Databases and add the user to the database with all privileges.') . '</p>' . $e
        . '<div class="row">' . '<div>' . field('db_host', t('Servidor', 'Host'), $cfg['db_host'] ?? 'localhost') . '</div><div>' . field('db_port', t('Puerto', 'Port'), (string) ($cfg['db_port'] ?? 3306)) . '</div></div>'
        . field('db_name', t('Nombre de la base de datos', 'Database name'), $cfg['db_name'] ?? '', 'text', t('Escríbelo exactamente como lo muestra cPanel (algunos hostings le agregan un prefijo).', 'Type it exactly as cPanel shows it (some hostings add a prefix).'))
        . field('db_user', t('Usuario', 'User'), $cfg['db_user'] ?? '')
        . field('db_pass', t('Contraseña', 'Password'), $cfg['db_pass'] ?? '', 'password', '', false);
    page(t('Base de datos', 'Database'), form($body, 2, t('Probar conexión y continuar', 'Test connection and continue')), 3);
}

if ($step === 4) {
    $body = '<p class="sub">' . t('La compañía y la cuenta del dueño, con la que entrarás.', 'The company and the owner account you will sign in with.') . '</p>' . $e
        . field('company', t('Nombre de la compañía', 'Company name'), $cfg['company'] ?? '')
        . '<div class="row"><div>' . field('owner_name', t('Tu nombre', 'Your name'), $cfg['owner_name'] ?? '') . '</div><div>' . field('owner_email', t('Tu correo', 'Your email'), $cfg['owner_email'] ?? '', 'email') . '</div></div>'
        . '<div class="row"><div>' . field('owner_pass', t('Contraseña', 'Password'), '', 'password', t('Mínimo 10 caracteres.', 'At least 10 characters.')) . '</div><div>' . field('owner_pass2', t('Repite la contraseña', 'Repeat the password'), '', 'password') . '</div></div>'
        . '<label for="f_locale">' . t('Idioma por omisión', 'Default language') . '</label><select id="f_locale" name="locale"><option value="es"' . (($cfg['locale'] ?? 'es') === 'es' ? ' selected' : '') . '>Español</option><option value="en"' . (($cfg['locale'] ?? '') === 'en' ? ' selected' : '') . '>English</option></select>';
    page(t('Compañía y dueño', 'Company and owner'), form($body, 3, t('Continuar', 'Continue')), 4);
}

if ($step === 5) {
    $body = '<p class="sub">' . t('Opcional: se puede dejar vacío y configurarlo después editando el archivo .env. Sin correo no llegan las invitaciones.', 'Optional: leave it empty and set it up later by editing the .env file. Without email, invitations are not delivered.') . '</p>' . $e
        . '<h3 style="margin:18px 0 0">' . t('Correo (SMTP, por ejemplo Brevo)', 'Email (SMTP, for example Brevo)') . '</h3>'
        . '<div class="row"><div>' . field('mail_host', t('Servidor SMTP', 'SMTP host'), $cfg['mail_host'] ?? '', 'text', 'smtp-relay.brevo.com', false) . '</div><div>' . field('mail_port', t('Puerto', 'Port'), (string) ($cfg['mail_port'] ?? 587), 'text', '', false) . '</div></div>'
        . '<div class="row"><div>' . field('mail_user', t('Usuario SMTP', 'SMTP user'), $cfg['mail_user'] ?? '', 'text', '', false) . '</div><div>' . field('mail_pass', t('Clave SMTP', 'SMTP key'), '', 'password', '', false) . '</div></div>'
        . field('mail_from', t('Correo remitente', 'Sender email'), $cfg['mail_from'] ?? '', 'email', t('Debe estar verificado en el proveedor.', 'It must be verified at the provider.'), false)
        . '<h3 style="margin:22px 0 0">Cloudflare Turnstile</h3>'
        . '<div class="row"><div>' . field('ts_site', t('Clave del sitio', 'Site key'), $cfg['ts_site'] ?? '', 'text', '', false) . '</div><div>' . field('ts_secret', t('Clave secreta', 'Secret key'), $cfg['ts_secret'] ?? '', 'text', '', false) . '</div></div>';
    page(t('Correo y seguridad', 'Email and security'), form($body, 4, t('Continuar', 'Continue')), 5);
}

/* step 6: summary + run */
$need = ['app_url', 'app_dir', 'db_name', 'db_user', 'company', 'owner_email', 'owner_pass'];
foreach ($need as $k) {
    if (empty($cfg[$k])) {
        header('Location: ?s=2');
        exit;
    }
}
if ($msg = appDirError(rtrim((string) $cfg['app_dir'], '/'), $WEB, false)) {
    $_SESSION['flash'] = $msg;
    header('Location: ?s=2');
    exit;
}
$sum = '<table><tr><td></td><td><b>' . t('Sitio', 'Site') . '</b><br>' . h($cfg['app_url']) . '/app/</td></tr>'
    . '<tr><td></td><td><b>' . t('Carpeta de la aplicación', 'Application folder') . '</b><br>' . h($cfg['app_dir']) . '</td></tr>'
    . '<tr><td></td><td><b>' . t('Base de datos', 'Database') . '</b><br>' . h($cfg['db_name']) . ' · ' . h($cfg['db_user']) . '@' . h($cfg['db_host']) . '</td></tr>'
    . '<tr><td></td><td><b>' . t('Compañía', 'Company') . '</b><br>' . h($cfg['company']) . ' · ' . h($cfg['owner_email']) . '</td></tr>'
    . '<tr><td></td><td><b>' . t('Correo', 'Email') . '</b><br>' . (empty($cfg['mail_host']) ? t('Sin configurar (después)', 'Not set up (later)') : h($cfg['mail_host'])) . '</td></tr></table>';
$js = '<div id="run" style="display:none"><div class="bar"><i id="bar"></i></div><p id="msg"></p></div><div id="out"></div>'
    . '<div class="btns" id="btns"><a class="btn ghost" href="?s=5">' . t('Atrás', 'Back') . '</a><button class="btn" id="go">' . t('Instalar ahora', 'Install now') . '</button></div>'
    . '<script>(function(){var T=' . json_encode(['ext' => t('Descomprimiendo archivos', 'Extracting files'), 'cfg' => t('Configurando la aplicación (tablas, compañía, cachés)…', 'Setting up the application (tables, company, caches)…'),
        'fail' => t('La instalación se detuvo: ', 'The installation stopped: '), 'retry' => t('Corrige lo indicado y vuelve a intentarlo.', 'Fix what is shown and try again.'),
        'done' => t('¡Listo! La aplicación quedó instalada.', 'Done! The application is installed.'), 'login' => t('Entrar a la aplicación', 'Open the application'),
        'cron' => t('Último paso, en cPanel → Cron Jobs: crea una tarea "Once Per Minute" con este comando (envía recordatorios y avisos):', 'Last step, in cPanel → Cron Jobs: create a "Once Per Minute" task with this command (sends reminders and notices):'),
        'again' => t('Reintentar', 'Retry')], JSON_UNESCAPED_UNICODE) . ',csrf=' . json_encode($_SESSION['csrf']) . ';'
    . 'var $=function(i){return document.getElementById(i)};function esc(s){var d=document.createElement("div");d.textContent=s;return d.innerHTML}'
    . 'function call(u){return fetch(u,{method:"POST",headers:{"X-CSRF":csrf}}).then(function(r){return r.text().then(function(t){try{return JSON.parse(t)}catch(e){return {ok:false,error:"HTTP "+r.status+" "+t.slice(0,300)}}})})}'
    . 'function fail(m){$("run").style.display="none";$("out").innerHTML="<div class=err>"+esc(T.fail+m)+"<br>"+esc(T.retry)+"</div>";$("btns").style.display="flex";$("go").textContent=T.again}'
    . 'function ext(from){call("?do=extract&from="+from).then(function(r){if(!r.ok)return fail(r.error);$("bar").style.width=Math.round(r.next/r.total*90)+"%";$("msg").textContent=T.ext+" "+r.next+" / "+r.total;'
    . 'if(!r.done)return ext(r.next);$("msg").textContent=T.cfg;$("bar").style.width="95%";call("?do=finish").then(function(f){if(!f.ok)return fail(f.error);$("bar").style.width="100%";$("run").style.display="none";'
    . 'var h="<div class=okb><b>"+esc(T.done)+"</b><ul>"+f.log.map(function(x){return "<li>"+esc(x)+"</li>"}).join("")+"</ul></div>";'
    . 'if(f.warn.length)h+="<div class=err>"+f.warn.map(esc).join("<br>")+"</div>";h+="<p>"+esc(T.cron)+"</p><pre>"+esc(f.cron)+"</pre><p><a class=btn href=\""+f.login+"\">"+esc(T.login)+"</a></p>";$("out").innerHTML=h})}).catch(function(e){fail(String(e))})}'
    . '$("go").onclick=function(){$("btns").style.display="none";$("out").innerHTML="";$("run").style.display="block";$("bar").style.width="2%";ext(0)}})();</script>';
page(t('Resumen e instalación', 'Summary and installation'), '<p class="sub">' . t('Revisa los datos y pulsa instalar. Tarda uno o dos minutos; no cierres la página.', 'Check the details and press install. It takes a minute or two; do not close the page.') . '</p>' . $sum . $js, 6);
