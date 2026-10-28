<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use App\Models\ApprovalToken;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Someter, aprobar y facturar exigen la contraseña de quien firma (campo `signature`), y el enlace del correo exige el código de 6 dígitos.
     * Las pruebas que no tratan de firmas firman solas: este arnés agrega la contraseña de quien está autenticado a esas peticiones y deja
     * verificado el código del enlace. Las pruebas de firmas lo desactivan con `$this->autoSign = false` y firman (o no) a mano.
     */
    protected bool $autoSign = true;

    /** La contraseña con la que firma esta persona en las pruebas (la de BuildsWorld). Se sobreescribe donde las cuentas usan otra. */
    protected function signatureFor(?User $user): string
    {
        return 'secret-password';
    }

    private function signaturePath(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: $uri;
        return (bool) preg_match('#^/api/(sheets/(\d+/(submit|approve|return|reopen)|submit-days)|internal/sheets/\d+/(approve|return)|approver/sheets/\d+/(approve|return)|contractor-invoices)$#', $path);
    }

    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        if ($this->autoSign && ! array_key_exists('signature', $data) && $this->signaturePath((string) $uri)) {
            $data['signature'] = $this->signatureFor(auth()->user());
        }
        return parent::postJson($uri, $data, $headers, $options);
    }

    /** El enlace del correo: con la firma automática, el código ya está verificado (como si el aprobador lo hubiera escrito). */
    private function verifyLinkCode(string $uri): void
    {
        if ($this->autoSign && preg_match('#^/a/([A-Za-z0-9]{48})(/approve|/return)?$#', (string) parse_url($uri, PHP_URL_PATH), $m)) {
            ApprovalToken::where('token_hash', ApprovalToken::hash($m[1]))->update(['code_verified_at' => now()]);
        }
    }

    public function get($uri, array $headers = [])
    {
        $this->verifyLinkCode((string) $uri);
        return parent::get($uri, $headers);
    }

    public function post($uri, array $data = [], array $headers = [])
    {
        $this->verifyLinkCode((string) $uri);
        return parent::post($uri, $data, $headers);
    }

    /**
     * Cómo se comporta la aplicación en pruebas (CSRF, sesión, caché, correo) no puede depender del entorno donde corran.
     * En un contenedor, por ejemplo, APP_ENV=local viene en $_SERVER y Laravel lo lee antes que lo que fija PHPUnit.
     */
    private const FIXED_ENV = [
        'APP_ENV' => 'testing', 'APP_MAINTENANCE_DRIVER' => 'file', 'BCRYPT_ROUNDS' => '4', 'BROADCAST_CONNECTION' => 'null',
        'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'TURNSTILE_SITE_KEY' => '', 'TURNSTILE_SECRET' => '',
    ];

    /**
     * Las pruebas BORRAN todas las tablas de la base que usan. Para que nunca se corran contra datos de verdad
     * (por ejemplo dentro del contenedor, que apunta a la base de desarrollo), solo se permite SQLite en memoria
     * o una base cuyo nombre contenga "test".
     */
    public function createApplication(): Application
    {
        foreach (self::FIXED_ENV as $key => $value) {
            putenv("$key=$value");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $conn = $app['config']->get('database.default');
        $db = (string) $app['config']->get("database.connections.$conn.database");
        $safe = $conn === 'sqlite' ? ($db === ':memory:' || str_contains(strtolower($db), 'test')) : str_contains(strtolower($db), 'test');
        if (! $safe) {
            throw new \RuntimeException(
                "Las pruebas se niegan a correr contra la base '$db' ($conn): las pruebas la borrarían. ".
                'Usa una base de pruebas, por ejemplo: docker compose exec -e DB_DATABASE=tiempo_test app php artisan test'
            );
        }
        return $app;
    }
}
