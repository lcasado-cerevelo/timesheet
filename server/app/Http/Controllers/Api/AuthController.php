<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Turnstile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Intentos fallidos permitidos por correo desde una misma IP, y minutos de bloqueo. */
    private const MAX_FAILURES = 5;
    private const LOCK_MINUTES = 15;

    /** Login de la app móvil (y de cualquier cliente que no sea la web). Protegido por límite por IP y bloqueo por intentos fallidos. */
    public function login(Request $request)
    {
        return $this->attempt($request);
    }

    /** Login de la web: además exige el desafío de Cloudflare Turnstile cuando está configurado. */
    public function loginWeb(Request $request)
    {
        if (Turnstile::enabled() && ! Turnstile::verify($request->input('turnstile_token'), $request->ip())) {
            throw ValidationException::withMessages(['turnstile' => 'No pudimos comprobar que eres una persona. Vuelve a intentarlo.']);
        }
        return $this->attempt($request);
    }

    /** Datos que la pantalla de login necesita antes de entrar (sin sesión). */
    public function publicConfig()
    {
        return response()->json(['turnstile_site_key' => Turnstile::enabled() ? config('services.turnstile.site_key') : null]);
    }

    private function attempt(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device' => 'nullable|string|max:80',
        ]);

        // Bloqueo por correo + IP: quien adivina contraseñas se bloquea a sí mismo sin dejar fuera al dueño legítimo desde otro sitio.
        $key = 'login:'.mb_strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            $min = (int) ceil(RateLimiter::availableIn($key) / 60);
            return response()->json(['message' => "Demasiados intentos fallidos. Espera $min minuto(s) y vuelve a intentarlo.", 'code' => 'locked'], 429);
        }

        $user = User::where('email', $data['email'])->first();
        // Se compara la clave aunque el usuario no exista para que el tiempo de respuesta no delate qué correos existen.
        $ok = Hash::check($data['password'], $user?->password ?? Hash::make(\Illuminate\Support\Str::random(24)));
        if (! $user || ! $user->active || ! $ok) {
            RateLimiter::hit($key, self::LOCK_MINUTES * 60);
            throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos.']);
        }

        RateLimiter::clear($key);
        $token = $user->createToken($data['device'] ?? 'app')->plainTextToken;
        return response()->json(['token' => $token, 'user' => $this->userPayload($user)]);
    }

    public function me(Request $request)
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['ok' => true]);
    }

    public static function userPayload(User $user): array
    {
        $company = $user->company;
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'worker_type' => $user->worker_type,
            'capture_mode' => $user->capture_mode,
            'weekly_hours' => $user->weekly_hours,
            'company' => ['id' => $company->id, 'name' => $company->name, 'timezone' => $company->timezone],
            // La app móvil lee la marca aquí: cambiarla en el servidor no requiere publicar la app.
            'brand' => $company->brand(),
            'rules' => $company->rules()->all(),
        ];
    }
}
