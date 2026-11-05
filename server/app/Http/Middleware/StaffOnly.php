<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Dueño u oficina. */
class StaffOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isStaff()) {
            return response()->json(['message' => __('Solo el personal de la oficina puede hacer esto.'), 'code' => 'staff_only'], 403);
        }
        return $next($request);
    }
}
