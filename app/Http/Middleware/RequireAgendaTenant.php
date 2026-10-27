<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class RequireAgendaTenant
{
    public function handle(Request $request, Closure $next)
    {
        if ($user = $request->user()) {
            abort_unless($user->tenant_id, 403);
            return TenantContext::runAs((int) $user->tenant_id, fn () => $next($request));
        }

        return $next($request);
    }
}
