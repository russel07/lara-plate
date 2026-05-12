<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $roles): Response
    {
        $user = $request->user();
        $requiredRoles = array_values(array_filter(array_map('trim', explode(',', $roles))));

        if (! $user || ! $user->hasRole($requiredRoles)) {
            abort(403, 'You do not have access to this resource.');
        }

        return $next($request);
    }
}