<?php

namespace App\Services;

use App\Mail\BrandedMail;
use App\Models\PasswordToken;
use App\Models\SecurityEvent;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invitaciones y reseteos de contraseña. Nadie (ni el dueño) escribe ni ve la contraseña de otra persona:
 * la persona la crea con un enlace de un solo uso (72 h la invitación, 24 h el reseteo) que se guarda con hash.
 */
class AccountService
{
    public const INVITE_HOURS = 72;
    public const RESET_HOURS = 24;

    /** Deja la cuenta sin contraseña utilizable hasta que la persona cree una. */
    public function lockPassword(User $user): void
    {
        $user->forceFill(['password' => Str::random(64), 'must_set_password' => true])->save();
    }

    /** Cierra todas las sesiones y los equipos recordados de la persona. */
    public function revokeAccess(User $user): void
    {
        $user->tokens()->delete();
        TrustedDevice::where('user_id', $user->id)->delete();
    }

    /** Crea el enlace (anula los anteriores sin usar) y devuelve el texto plano; solo se guarda el hash. */
    public function issueToken(User $user, string $purpose): string
    {
        PasswordToken::where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
        $plain = Str::random(48);
        PasswordToken::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'token_hash' => PasswordToken::hash($plain),
            'expires_at' => now()->addHours($purpose === 'invite' ? self::INVITE_HOURS : self::RESET_HOURS),
            'created_at' => now(),
        ]);
        return $plain;
    }

    public function linkFor(User $user, string $plain): string
    {
        return $user->company->baseUrl().'/app/#/set-password/'.$plain;
    }

    /** Envía (o reenvía) la invitación. La cuenta queda pendiente de que la persona cree su contraseña. */
    public function invite(User $user, ?User $actor = null): void
    {
        $this->lockPassword($user);
        $plain = $this->issueToken($user, 'invite');
        $this->mailLink($user, 'invite', $plain);
        $this->log('invitation_sent', $user, $actor);
    }

    /** Reseteo hecho por el dueño (o por la consola): contraseña anterior inservible, sesiones cerradas, enlace nuevo. Devuelve el enlace. */
    public function reset(User $user, ?User $actor = null, array $data = [], bool $mailFailureIsFatal = true): string
    {
        $this->lockPassword($user);
        $this->revokeAccess($user);
        $plain = $this->issueToken($user, 'reset');
        try {
            $this->mailLink($user, 'reset', $plain);
        } catch (\Throwable $e) {
            if ($mailFailureIsFatal) {
                throw $e;
            }
            report($e);
        }
        $this->log('password_reset', $user, $actor, $data);
        return $this->linkFor($user, $plain);
    }

    public function findToken(?string $plain): ?PasswordToken
    {
        if (! $plain) {
            return null;
        }
        $t = PasswordToken::where('token_hash', PasswordToken::hash($plain))->first();
        return $t && $t->isUsable() ? $t : null;
    }

    /** Crea la contraseña con el enlace de un solo uso. No inicia sesión. */
    public function redeem(PasswordToken $token, string $password): User
    {
        $user = $token->user;
        $user->forceFill(['password' => $password, 'must_set_password' => false])->save();
        PasswordToken::where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
        $this->log('password_set', $user, null);
        return $user;
    }

    public function log(string $type, ?User $target, ?User $actor, array $data = []): void
    {
        $req = request();
        SecurityEvent::create([
            'company_id' => $target?->company_id ?? $actor?->company_id,
            'type' => $type,
            'actor_user_id' => $actor?->id,
            'target_user_id' => $target?->id,
            'ip' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 300) : null,
            'data' => $data ?: null,
            'created_at' => now(),
        ]);
    }

    private function mailLink(User $user, string $purpose, string $plain): void
    {
        $user->loadMissing('company');
        $lang = $user->lang();
        Lang::with($lang, function () use ($user, $purpose, $plain, $lang) {
            $hours = $purpose === 'invite' ? self::INVITE_HOURS : self::RESET_HOURS;
            $subject = $purpose === 'invite'
                ? __('Te invitaron a :company: crea tu contraseña', ['company' => $user->company->displayName()])
                : __('Crea una contraseña nueva para entrar a :company', ['company' => $user->company->displayName()]);
            Mail::to($user->email, $user->name)->locale($lang)->send(new BrandedMail(
                $user->company, $subject, 'mail.account-'.$purpose,
                ['name' => $user->name, 'url' => $this->linkFor($user, $plain), 'hours' => $hours, 'companyName' => $user->company->displayName()]
            ));
        });
    }
}
