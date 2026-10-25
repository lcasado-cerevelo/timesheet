<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class OwnerOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isOwner()) {
            return response()->json(['message' => __('Solo el dueño de la compañía puede hacer esto.'), 'code' => 'owner_only'], 403);
        }
        return $next($request);
    }
}
