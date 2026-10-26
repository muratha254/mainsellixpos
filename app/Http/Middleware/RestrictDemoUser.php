<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RestrictDemoUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! $user->company_id) {
            return $next($request);
        }

        $company = $user->relationLoaded('company') ? $user->company : $user->company()->first();
        if (! $company || ! $company->is_demo) {
            return $next($request);
        }

        if ($request->routeIs(
            'owner.*',
            'settings.backup',
            'settings.backup.*',
            'users.create',
            'users.store',
            'users.edit',
            'users.update',
            'users.destroy',
            'roles.create',
            'roles.store',
            'roles.edit',
            'roles.update',
            'roles.destroy'
        )) {
            abort(403, 'This action is not available in the demo.');
        }

        return $next($request);
    }
}
