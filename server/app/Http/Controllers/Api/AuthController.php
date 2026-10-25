<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device' => 'nullable|string|max:80',
        ]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! $user->active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos.']);
        }
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
