<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Qué contraseñas se aceptan al crearlas o cambiarlas (el largo mínimo lo pone cada formulario): que no sea el correo ni lo contenga,
 * que no sea de las más comunes o un patrón obvio, y que no haya aparecido en filtraciones de datos conocidas.
 * Lo de las filtraciones usa Have I Been Pwned (solo se envían los primeros 5 caracteres del SHA-1, nunca la contraseña) y, si no
 * hay respuesta, no bloquea.
 */
class PasswordPolicy
{
    /** Contraseñas largas pero conocidas por todos (todas con 10 o más caracteres, en minúsculas). */
    private const COMMON = [
        '1234567890', '0123456789', '12345678910', '123456789012', '1234567890123', '0987654321', 'qwertyuiop', 'qwertyuiop1', 'qwerty1234', 'qwerty12345',
        'qwerty123456', 'asdfghjkl1', 'asdfghjkl123', 'zxcvbnm123', '1q2w3e4r5t', '1q2w3e4r5t6y', 'q1w2e3r4t5', 'qazwsxedc123', 'password12',
        'password123', 'password1234', 'password12345', 'passw0rd123', 'p@ssword123', 'p@ssw0rd123', 'password!23', 'contraseña1', 'contraseña12', 'contraseña123',
        'contrasena123', 'contrasena12', 'iloveyou123', 'iloveyou12', 'welcome123', 'welcome1234', 'bienvenido1', 'bienvenido123', 'administrator', 'admin123456',
        'administrador', 'changeme123', 'letmein123', 'letmein1234', 'trustno1234', 'abc1234567', 'abcd123456', 'abcdefghij', 'abcdefg123', 'monkey12345',
        'dragon12345', 'football123', 'baseball123', 'superman123', 'princess123', 'sunshine123', 'master12345', 'shadow12345', 'michael1234', 'jordan23456',
        'cerevelo123', 'cerevelo1234', 'timesheet123', 'timesheet1234', 'tiempo12345', 'puertorico1', 'puertorico123', 'borinquen123', 'passwordpassword',
        '1111111111', '0000000000', '1234512345', '1212121212', '1010101010', 'aaaaaaaaaa', 'qqqqqqqqqq', 'asdasdasd1', 'test123456', 'testing1234',
        'default1234', 'secret12345', 'google12345', 'hello12345', 'hola123456', 'holamundo12', 'mipassword1', 'micontraseña', 'micontrasena', 'clave123456',
        'clave12345', 'usuario1234', 'usuario12345',
    ];

    /** @throws ValidationException */
    public static function assert(string $password, string $email): void
    {
        $lower = mb_strtolower($password);
        $mail = mb_strtolower($email);
        $local = explode('@', $mail)[0];
        if ($lower === $mail) {
            self::fail(__('La contraseña no puede ser igual al correo.'));
        }
        if (mb_strlen($local) >= 4 && str_contains($lower, $local)) {
            self::fail(__('La contraseña no puede contener tu correo.'));
        }
        if (in_array($lower, self::COMMON, true) || self::isPattern($lower)) {
            self::fail(__('Esa contraseña es demasiado común. Escoge otra más difícil de adivinar.'));
        }
        if (! app()->runningUnitTests() && self::pwned($password)) {
            self::fail(__('Esa contraseña apareció en filtraciones de datos. Escoge otra que no hayas usado en ningún otro sitio.'));
        }
    }

    /** Un solo carácter repetido, una secuencia de números seguidos o un bloque corto repetido (abcabcabcabc). */
    private static function isPattern(string $p): bool
    {
        if (preg_match('/^(.)\1+$/u', $p) || preg_match('/^(.{1,3})\1{3,}$/u', $p)) {
            return true;
        }
        if (ctype_digit($p)) {
            $up = true;
            $down = true;
            for ($i = 1; $i < strlen($p); $i++) {
                $d = (int) $p[$i] - (int) $p[$i - 1];
                $up = $up && ($d === 1 || $d === -9);
                $down = $down && ($d === -1 || $d === 9);
            }
            return $up || $down;
        }
        return false;
    }

    /** ¿Aparece en filtraciones conocidas? Si el servicio no responde, no se bloquea a nadie por eso. */
    public static function pwned(string $password): bool
    {
        try {
            $sha = strtoupper(sha1($password));
            $r = Http::timeout(3)->withHeaders(['Add-Padding' => 'true', 'User-Agent' => 'Cerevelo-Timesheet'])
                ->get('https://api.pwnedpasswords.com/range/'.substr($sha, 0, 5));
            if (! $r->successful()) {
                return false;
            }
            foreach (preg_split('/\r?\n/', $r->body()) as $line) {
                [$suffix, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
                if ($suffix === substr($sha, 5) && (int) $count > 0) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // sin conexión o con tiempo agotado: se acepta
        }
        return false;
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['password' => $message]);
    }
}
