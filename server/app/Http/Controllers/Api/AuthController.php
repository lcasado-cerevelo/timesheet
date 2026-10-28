<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\Company;
use App\Models\User;
use App\Services\AccountService;
use App\Services\TwoStepService;
use App\Support\Turnstile;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Intentos fallidos permitidos por correo desde una misma IP, y minutos de bloqueo. */
    private const MAX_FAILURES = 5;
    private const LOCK_MINUTES = 15;

    public function __construct(private TwoStepService $twoStep, private AccountService $accounts)
    {
    }

    /** Login de la app móvil (sin Turnstile ni segundo paso). Las cuentas de aprobador solo entran por la web. */
    public function login(Request $request)
    {
        $user = $this->credentials($request);
        if ($user->isApprover()) {
            return response()->json(['message' => __('Las cuentas de aprobador solo entran por la web.'), 'code' => 'approver_web_only'], 403);
        }
        // El dueño y la oficina también dan el código por correo (la app lo pide y recuerda el equipo).
        if ($this->twoStep->required($user) && ! $this->twoStep->deviceIsValid($user, $request->input('device_token'))) {
            return response()->json(['step' => 'code'] + $this->twoStep->start($user), 202);
        }
        return $this->issue($user, $request->input('device'));
    }

    /** Login de la web: Turnstile si está configurado; el dominio de la compañía; y código por correo para aprobadores. */
    public function loginWeb(Request $request)
    {
        if (Turnstile::enabled() && ! Turnstile::verify($request->input('turnstile_token'), $request->ip())) {
            throw ValidationException::withMessages(['turnstile' => __('No pudimos comprobar que eres una persona. Vuelve a intentarlo.')]);
        }
        $user = $this->credentials($request, true);
        if ($this->twoStep->required($user) && ! $this->twoStep->deviceIsValid($user, $request->input('device_token'))) {
            return response()->json(['step' => 'code'] + $this->twoStep->start($user), 202);
        }
        return $this->issue($user, $request->input('device'));
    }

    /** Segundo paso: código de 6 dígitos enviado por correo. */
    public function loginCode(Request $request)
    {
        $v = $request->validate(['challenge' => 'required|string', 'code' => 'required|string|max:12', 'remember' => 'sometimes|boolean', 'device' => 'nullable|string|max:80']);
        $ch = $this->twoStep->find($v['challenge']);
        if (! $ch) {
            throw ValidationException::withMessages(['code' => __('El código venció. Inicia sesión de nuevo.')]);
        }
        $user = $this->twoStep->verify($ch, $v['code']);
        if (! $user->active || $user->must_set_password) {
            throw ValidationException::withMessages(['code' => __('El código no es correcto.')]);
        }
        $device = ! empty($v['remember']) ? $this->twoStep->issueDevice($user) : null;
        $res = $this->issue($user, $request->input('device') ?: 'web')->getData(true);
        return response()->json($res + ($device ? ['device_token' => $device] : []));
    }

    public function loginCodeResend(Request $request)
    {
        $v = $request->validate(['challenge' => 'required|string']);
        $ch = $this->twoStep->find($v['challenge']);
        if (! $ch) {
            throw ValidationException::withMessages(['challenge' => __('El código venció. Inicia sesión de nuevo.')]);
        }
        $wait = $this->twoStep->resend($ch);
        if ($wait !== null) {
            return response()->json(['message' => __('Espera :seconds segundos para pedir otro código.', ['seconds' => $wait]), 'retry_after' => $wait], 429);
        }
        return response()->json(['ok' => true]);
    }

    /** Datos que la pantalla de login necesita antes de entrar (sin sesión). */
    public function publicConfig(Request $request)
    {
        $company = Company::forHost($request->getHost());
        if (! $company && Company::count() === 1) {
            $company = Company::first();
        }
        $explicit = in_array(strtolower(substr((string) $request->header('X-Locale'), 0, 2)), SetLocale::SUPPORTED, true);
        $locale = $company && ! $explicit ? $company->lang() : App::getLocale();
        return response()->json([
            'turnstile_site_key' => Turnstile::enabled() ? config('services.turnstile.site_key') : null,
            'locale' => $locale,
            'brand' => $company?->publicBrand(),
            'demo' => (bool) config('app.demo'),
            'demo_accounts' => config('app.demo') ? \App\Support\Demo::publicAccounts() : [],
        ]);
    }

    private function issue(User $user, ?string $device)
    {
        $token = $user->createToken($device ?: 'app')->plainTextToken;
        return response()->json(['token' => $token, 'user' => $this->userPayload($user)]);
    }

    /** Valida correo y contraseña con bloqueo por intentos fallidos. Devuelve la persona o lanza el mismo error genérico. */
    private function credentials(Request $request, bool $web = false): User
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
            throw new HttpResponseException(response()->json(['message' => __('Demasiados intentos fallidos. Espera :min minuto(s) y vuelve a intentarlo.', ['min' => $min]), 'code' => 'locked'], 429));
        }

        $user = User::with('company')->where('email', $data['email'])->first();
        // Se compara la clave aunque el usuario no exista para que el tiempo de respuesta no delate qué correos existen.
        $ok = Hash::check($data['password'], $user?->password ?? Hash::make(\Illuminate\Support\Str::random(24)));
        // Quien pertenece a una compañía con dominio solo entra por ese dominio.
        $wrongHost = $web && $user && $user->company->domain && $user->company->domain !== strtolower($request->getHost());
        if (! $user || ! $user->active || ! $ok || $wrongHost) {
            RateLimiter::hit($key, self::LOCK_MINUTES * 60);
            throw ValidationException::withMessages(['email' => __('Correo o contraseña incorrectos.')]);
        }

        RateLimiter::clear($key);
        return $user;
    }

    public function me(Request $request)
    {
        return response()->json($this->userPayload($request->user()));
    }

    /** Cada persona escoge su idioma (es o en). */
    public function setLocale(Request $request)
    {
        $v = $request->validate(['locale' => 'required|in:es,en']);
        $request->user()->update(['locale' => $v['locale']]);
        return response()->json($this->userPayload($request->user()->fresh()));
    }

    /**
     * El teléfono activó (o quitó) la entrada con huella: la sesión de ese teléfono se marca y deja de vencer por inactividad
     * (con "Recordar por" en 0 el respaldo es de 12 horas). El tope absoluto de la sesión sigue valiendo.
     */
    public function setBiometric(Request $request)
    {
        $v = $request->validate(['enabled' => 'required|boolean']);
        $token = $request->user()->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $abilities = array_values(array_diff((array) ($token->abilities ?: ['*']), ['bio']));
            if ($v['enabled']) {
                $abilities[] = 'bio';
            }
            $token->forceFill(['abilities' => $abilities])->save();
        }
        return response()->json(['enabled' => (bool) $v['enabled']]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['ok' => true]);
    }

    public static function userPayload(User $user): array
    {
        $company = $user->company;
        $extra = $user->isApprover() ? ['approver_id' => $user->approver_profile_id, 'two_step' => true,
            'project_manager' => (bool) \App\Models\Approver::whereKey($user->approver_profile_id)->where('project_manager', true)->where('active', true)->exists()] : [];
        return $extra + [
            'demo' => (bool) config('app.demo'),
            'internal_approver' => ! $user->isApprover() && $user->isInternalApprover(),
            // Con comisión activa, la persona ve la pantalla "Mis comisiones".
            'has_commissions' => ! $user->isApprover() && \App\Models\Commission::where('user_id', $user->id)->where('active', true)->exists(),
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'worker_type' => $user->worker_type,
            'capture_mode' => $user->capture_mode,
            'member_since' => $user->created_at?->toDateString(),   // desde cuándo existe la cuenta: no se reclaman semanas anteriores
            'locale' => $user->locale ?: $company->locale,   // idioma en uso (es o en)
            'company_locale' => $company->locale,
            'weekly_hours' => $user->weekly_hours,
            'employee_number' => $user->employee_number,
            'company' => ['id' => $company->id, 'name' => $company->name, 'timezone' => $company->timezone],
            // La app móvil lee la marca aquí: cambiarla en el servidor no requiere publicar la app.
            'brand' => $company->brand(),
            'rules' => $company->rules()->all() + ['minimum_minutes' => $company->rules()->minimumMinutes()],
            'remember_days' => (int) $company->rules()->get('remember_days'),
        ];
    }
}
