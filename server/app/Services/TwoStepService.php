<?php

namespace App\Services;

use App\Mail\BrandedMail;
use App\Models\LoginChallenge;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Segundo paso al entrar por la web con cuenta de aprobador: código de 6 dígitos por correo y equipos recordados. */
class TwoStepService
{
    public const MAX_ATTEMPTS = 5;
    public const MAX_SENDS = 5;
    public const CODE_MINUTES = 10;

    /**
     * ¿Esta cuenta debe dar el código por correo al entrar? Siempre las de aprobador; el dueño y la oficina también si la regla
     * está activa y el correo está configurado (sin correo no podrían recibir el código y quedarían fuera).
     */
    public function required(User $user): bool
    {
        if ($user->isApprover()) {
            return true;
        }
        return $user->isOwner() && (bool) $user->company->rules()->get('two_step_office')
            && ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /** @return array{challenge:string,email_hint:string,expires_in:int,remember_days:int} */
    public function start(User $user): array
    {
        $plain = Str::random(40);
        $code = $this->newCode();
        $ch = LoginChallenge::create([
            'user_id' => $user->id,
            'challenge_hash' => LoginChallenge::hash($plain),
            'code_hash' => 'x',
            'code_expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes(self::CODE_MINUTES * 3),
            'created_at' => now(),
        ]);
        $ch->update(['code_hash' => $this->codeHash($ch, $code)]);
        $this->mail($user, $code);
        return [
            'challenge' => $plain,
            'email_hint' => self::hint($user->email),
            'expires_in' => self::CODE_MINUTES * 60,
            'remember_days' => (int) $user->company->rules()->get('remember_days'),
        ];
    }

    public function find(?string $plain): ?LoginChallenge
    {
        if (! $plain) {
            return null;
        }
        $ch = LoginChallenge::where('challenge_hash', LoginChallenge::hash($plain))->first();
        return $ch && $ch->used_at === null && $ch->expires_at->isFuture() ? $ch : null;
    }

    /** Reenvía un código nuevo (máximo uno por minuto y 5 por desafío). Devuelve los segundos de espera si aún no toca, o null si lo envió. */
    public function resend(LoginChallenge $ch): ?int
    {
        $wait = 60 - (int) $ch->last_sent_at->diffInSeconds(now(), true);
        if ($wait > 0) {
            return $wait;
        }
        if ($ch->sends >= self::MAX_SENDS) {
            throw ValidationException::withMessages(['challenge' => __('Pediste demasiados códigos. Vuelve a iniciar sesión.')]);
        }
        $code = $this->newCode();
        $ch->forceFill([
            'code_hash' => $this->codeHash($ch, $code), 'attempts' => 0, 'sends' => $ch->sends + 1,
            'code_expires_at' => now()->addMinutes(self::CODE_MINUTES), 'last_sent_at' => now(),
        ])->save();
        $this->mail($ch->user()->with('company')->first(), $code);
        return null;
    }

    /** Verifica el código; consume el desafío si es correcto. Lanza 422 si no. */
    public function verify(LoginChallenge $ch, string $code): User
    {
        if ($ch->attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => __('Demasiados intentos. Vuelve a iniciar sesión.')]);
        }
        if ($ch->code_expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => __('El código venció. Pide uno nuevo.')]);
        }
        $ch->increment('attempts');
        if (! hash_equals($ch->code_hash, $this->codeHash($ch, trim($code)))) {
            throw ValidationException::withMessages(['code' => __('El código no es correcto.')]);
        }
        $ch->forceFill(['used_at' => now()])->save();
        return $ch->user()->with('company')->first();
    }

    public function issueDevice(User $user): ?string
    {
        $days = (int) $user->company->rules()->get('remember_days');
        if ($days <= 0) {
            return null;
        }
        // Un equipo recordado no vale más que lo que dura la sesión de esa cuenta.
        $cap = \App\Support\SessionExpiry::capDays($user);
        if ($cap > 0) {
            $days = min($days, $cap);
        }
        $plain = Str::random(60);
        TrustedDevice::create(['user_id' => $user->id, 'token_hash' => TrustedDevice::hash($plain),
            'expires_at' => now()->addDays($days), 'created_at' => now()]);
        return $plain;
    }

    public function deviceIsValid(User $user, ?string $plain): bool
    {
        if (! $plain || (int) $user->company->rules()->get('remember_days') <= 0) {
            return false;
        }
        $d = TrustedDevice::where('user_id', $user->id)->where('token_hash', TrustedDevice::hash($plain))->first();
        if (! $d || $d->expires_at->isPast()) {
            return false;
        }
        $d->forceFill(['last_used_at' => now()])->save();
        return true;
    }

    public static function hint(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    private function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function codeHash(LoginChallenge $ch, string $code): string
    {
        return hash('sha256', $ch->id.'|'.$code);
    }

    private function mail(User $user, string $code): void
    {
        $lang = $user->lang();
        Lang::with($lang, function () use ($user, $code, $lang) {
            Mail::to($user->email, $user->name)->locale($lang)->send(new BrandedMail(
                $user->company, __('Tu código de verificación: :code', ['code' => $code]), 'mail.approval-code',
                ['code' => $code, 'minutes' => self::CODE_MINUTES]
            ));
        });
    }
}
