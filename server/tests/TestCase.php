<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cómo se comporta la aplicación en pruebas (CSRF, sesión, caché, correo) no puede depender del entorno donde corran.
     * En un contenedor, por ejemplo, APP_ENV=local viene en $_SERVER y Laravel lo lee antes que lo que fija PHPUnit.
     */
    private const FIXED_ENV = [
        'APP_ENV' => 'testing', 'APP_MAINTENANCE_DRIVER' => 'file', 'BCRYPT_ROUNDS' => '4', 'BROADCAST_CONNECTION' => 'null',
        'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
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
