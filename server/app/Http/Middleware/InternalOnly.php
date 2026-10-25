<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Solo quien es aprobador interno de al menos un proyecto activo (supervisor). */
class InternalOnly
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if (! $u || $u->isApprover() || ! $u->isInternalApprover()) {
            return response()->json(['message' => __('Solo los aprobadores internos pueden hacer esto.'), 'code' => 'internal_required'], 403);
        }
        return $next($request);
    }
}
