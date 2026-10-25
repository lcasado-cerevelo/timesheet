<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/** Crear contraseña con enlace de un solo uso, cambiarla (pidiendo la actual) y consulta de dominio para Caddy. */
class PasswordController extends Controller
{
    public function __construct(private AccountService $accounts)
    {
    }

    public function showSetup(Request $request)
    {
        $t = $this->accounts->findToken((string) $request->query('token'));
        abort_unless($t, 404, __('Este enlace ya no es válido o venció. Pide uno nuevo.'));
        $u = $t->user()->with('company')->first();
        return response()->json(['email' => $u->email, 'name' => $u->name, 'purpose' => $t->purpose, 'brand' => $u->company->publicBrand()]);
    }

    public function setup(Request $request)
    {
        $v = $request->validate(['token' => 'required|string', 'password' => 'required|string|min:10|max:200']);
        $t = $this->accounts->findToken($v['token']);
        abort_unless($t, 404, __('Este enlace ya no es válido o venció. Pide uno nuevo.'));
        $this->assertNotEmail($v['password'], $t->user->email);
        $this->accounts->redeem($t, $v['password']);
        return response()->json(['ok' => true]);
    }

    public function change(Request $request)
    {
        $v = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:10|max:200|different:current_password',
        ]);
        $user = $request->user();
        if (! Hash::check($v['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('La contraseña actual no es correcta.')]);
        }
        $this->assertNotEmail($v['password'], $user->email);
        $user->forceFill(['password' => $v['password']])->save();
        $current = $user->currentAccessToken();
        $keep = $current instanceof PersonalAccessToken ? $current->id : null;
        $user->tokens()->when($keep, fn ($q) => $q->where('id', '!=', $keep))->delete();
        $this->accounts->log('password_changed', $user, $user);
        return response()->json(['ok' => true]);
    }

    /** Lo consulta Caddy (on_demand_tls ask): 200 si alguna compañía tiene ese dominio. */
    public function domainCheck(Request $request)
    {
        $d = strtolower(trim((string) $request->query('domain')));
        return Company::where('domain', $d)->exists() && $d !== ''
            ? response()->json(['ok' => true])
            : response()->json(['ok' => false], 404);
    }

    private function assertNotEmail(string $password, string $email): void
    {
        if (mb_strtolower($password) === mb_strtolower($email)) {
            throw ValidationException::withMessages(['password' => __('La contraseña no puede ser igual al correo.')]);
        }
    }
}
