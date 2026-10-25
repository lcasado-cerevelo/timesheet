<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Una cuenta de aprobador solo usa su perfil, el cambio de contraseña, cerrar sesión y /api/approver/*. Todo lo demás es 403. */
class ApproverScope
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->isApprover()) {
            $allowed = $request->is('api/me', 'api/me/locale', 'api/password/change', 'api/logout', 'api/approver/*');
            if (! $allowed) {
                return response()->json(['message' => __('Esta cuenta solo puede aprobar hojas.'), 'code' => 'approver_only'], 403);
            }
        }
        return $next($request);
    }
}
