<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        $requiredPermissions = array_values(array_filter(array_map('trim', explode(',', $permissions))));

        if (! $user || ! $user->hasPermission($requiredPermissions)) {
            abort(403, 'You do not have permission to access this resource.');
        }

        return $next($request);
    }
}