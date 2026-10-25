<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApproverOnly
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if (! $u?->isApprover() || ! $u->approver_profile_id) {
            return response()->json(['message' => __('Solo las cuentas de aprobador pueden hacer esto.'), 'code' => 'approver_required'], 403);
        }
        return $next($request);
    }
}
