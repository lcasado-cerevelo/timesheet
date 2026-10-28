<?php

namespace App\Services;

use App\Models\Signature;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Firma digital con contraseña: quien firma vuelve a escribir su contraseña (campo `signature` de la petición; en pantalla el campo
 * cambia de nombre cada vez para que el navegador no lo autocomplete). Se guarda quién, qué, cuándo, la IP y una huella (sha256)
 * del contenido firmado, para que los informes digan "firmado digitalmente por …".
 */
class SignatureService
{
    private const MAX_FAILURES = 5;
    private const LOCK_MINUTES = 15;

    /** Verifica la contraseña y deja constancia. $payload es lo que se firma (la huella cambia si ese contenido cambia). */
    public function sign(Request $request, User $user, string $action, ?Model $subject, array $payload = []): Signature
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

        $now = now();
        ksort($payload);
        return Signature::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => $subject ? strtolower(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'fingerprint' => hash('sha256', json_encode([$action, $user->id, $subject?->getKey(), $now->toIso8601String(), $payload], JSON_UNESCAPED_UNICODE)),
            'ip' => $request->ip(),
            'meta' => $payload ?: null,
            'signed_at' => $now,
        ]);
    }
}
