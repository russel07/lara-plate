<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Organization;
use App\Models\PersonalAccessToken;

class IdentifyTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organization = null;
        $tenantHeaderProvided = false;
        $bearerTokenProvided = false;

        // 1) Highest priority: explicit X-Tenant header
        $tenantHeader = $request->header('X-Tenant');
        $tenantHeaderProvided = !empty($tenantHeader);
        if (!empty($tenantHeader)) {
            $organization = Organization::where('slug', $tenantHeader)->first();
        }

        // 2) Fallback: resolve from host subdomain (only if header was not provided)
        if ( ! $organization && empty($tenantHeader) ) {
            $host = $request->getHost();
            $hostWithoutDevSuffix = preg_replace('/\.localhost$|\.test$/', '', $host);
            $parts = explode('.', $hostWithoutDevSuffix);
            $candidateSlug = $parts[0] ?? null;

            if ( ! empty($candidateSlug) && ! in_array($candidateSlug, ['localhost', '127', 'api']) ) {
                $organization = Organization::where('slug', $candidateSlug)->first();
            }
        }

        // 3) Final fallback: infer organization from Bearer token's tokenable user
        if ( ! $organization && empty($tenantHeader) ) {
            $bearerToken = $request->bearerToken();
            $bearerTokenProvided = !empty($bearerToken);

            if ( ! empty($bearerToken) ) {
                $personalAccessToken = PersonalAccessToken::findToken($bearerToken);
                $tokenable = $personalAccessToken?->tokenable;

                if ($tokenable && ! empty($tokenable->organization_id)) {
                    $organization = Organization::find($tokenable->organization_id);
                }
            }
        }

        if ( ! $organization ) {
            // Distinguish between missing tenant context vs invalid tenant/org.
            if ( ! $tenantHeaderProvided && ! $bearerTokenProvided ) {
                return response()->json([
                    'status' => false,
                    'message' => 'Tenant context missing. Use X-Tenant header or a valid Bearer token.',
                ], 400);
            }

            return response()->json([
                'status' => false,
                'message' => 'Organization not found.',
            ], 404);
        }

        // 4) Bind organization globally for models/middleware
        app()->instance('currentOrganization', $organization);

        // 5) Attach organization_id to the request
        $request->merge([
            'organization_id' => $organization->id,
        ]);

        return $next($request);
    }
}