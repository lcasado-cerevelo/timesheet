<?php

namespace App\Services;

use App\Models\Approver;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Firma digital: quien firma vuelve a escribir su contraseña (campo `signature` de la petición; en pantalla el campo cambia de nombre
 * cada vez para que el navegador no lo autocomplete), o, si es un aprobador externo que aprueba desde el enlace del correo, usa el código
 * de 6 dígitos que le llegó. Se guarda quién, qué, cuándo, la IP y una huella (sha256) del contenido firmado, para que los informes digan
 * "firmado digitalmente por …".
 *
 * Con contraseña se hace en dos tiempos cuando la acción puede fallar por otras razones: primero verifyPassword() (antes de hacer nada) y,
 * si la acción salió bien, record() (así no queda firmada una acción que no se hizo).
 */
class SignatureService
{
    private const MAX_FAILURES = 5;
    private const LOCK_MINUTES = 15;

    /** Verifica la contraseña (con bloqueo por intentos fallidos). No deja constancia. */
    public function verifyPassword(Request $request, User $user): void
    {
        $key = 'sign:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            $min = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw new HttpResponseException(response()->json([
                'message' => __('Demasiados intentos fallidos. Espera :min minuto(s) y vuelve a intentarlo.', ['min' => $min]), 'code' => 'locked'], 429));
        }
        $password = $request->input('signature');
        if (! is_string($password) || $password === '' || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::LOCK_MINUTES * 60);
            throw ValidationException::withMessages(['signature' => __('La contraseña no es correcta: no se pudo firmar.')]);
        }
        RateLimiter::clear($key);
    }

    /** Deja constancia de una firma con contraseña (ya verificada). $payload es lo que se firma: la huella cambia si ese contenido cambia. */
    public function record(Request $request, User $user, string $action, ?Model $subject, array $payload = []): Signature
    {
        return $this->store($request, $action, 'password', $user->name, $user->id, null, $user->company_id, $subject, $payload);
    }

    /** Verifica la contraseña y deja constancia, para las acciones que no pueden fallar después. */
    public function sign(Request $request, User $user, string $action, ?Model $subject, array $payload = []): Signature
    {
        $this->verifyPassword($request, $user);
        return $this->record($request, $user, $action, $subject, $payload);
    }

    /** Firma de un aprobador externo que aprueba desde el enlace del correo: el código de 6 dígitos ya verificado hace de contraseña. */
    public function recordByCode(Request $request, Approver $approver, string $action, ?Model $subject, array $payload = []): Signature
    {
        return $this->store($request, $action, 'email_code', $approver->name, null, $approver->id, $approver->company_id, $subject, $payload);
    }

    /**
     * Las firmas de una hoja en su ciclo actual (desde el último envío firmado): quién envió y quién aprobó o devolvió.
     * Las hojas anteriores a las firmas no tienen ninguna.
     * @return array<int, array{action:string, by:string, method:string, at:string, fingerprint:string}>
     */
    public function forSheet(Model $sheet): array
    {
        $all = Signature::where('subject_type', 'timesheet')->where('subject_id', $sheet->getKey())->orderBy('id')->get();
        $lastSubmit = $all->where('action', 'sheet.submit')->last();
        if (! $lastSubmit) {
            return [];
        }
        return $all->filter(fn ($s) => $s->id >= $lastSubmit->id)->map(fn ($s) => [
            'action' => $s->action, 'by' => $s->signer_name ?: $s->user?->name, 'method' => $s->method,
            'at' => $s->signed_at->toIso8601String(), 'fingerprint' => substr($s->fingerprint, 0, 12),
        ])->values()->all();
    }

    private function store(Request $request, string $action, string $method, ?string $name, ?int $userId, ?int $approverId, int $companyId, ?Model $subject, array $payload): Signature
    {
        $now = now();
        ksort($payload);
        return Signature::create([
            'company_id' => $companyId, 'user_id' => $userId, 'approver_id' => $approverId, 'action' => $action, 'method' => $method, 'signer_name' => $name,
            'subject_type' => $subject ? strtolower(class_basename($subject)) : null, 'subject_id' => $subject?->getKey(),
            'fingerprint' => hash('sha256', json_encode([$action, $method, $userId ?? 'approver:'.$approverId, $subject?->getKey(), $now->toIso8601String(), $payload], JSON_UNESCAPED_UNICODE)),
            'ip' => $request->ip(), 'meta' => $payload ?: null, 'signed_at' => $now,
        ]);
    }
}
