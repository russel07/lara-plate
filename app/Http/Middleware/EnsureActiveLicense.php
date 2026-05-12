<?php

namespace App\Http\Middleware;

use App\Models\LicenseActivation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveLicense
{
    /**
     * Route path suffixes that are accessible even without an active license.
     * These allow users to verify their email, log out, or activate a license.
     */
    private const EXEMPT_SUFFIXES = [
        'verify-otp',
        'resend-otp',
        'logout',
        'me',
        'activate-license',
        'me/permissions',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Central routes or no tenant context — skip
        if (! app()->has('currentOrganization')) {
            return $next($request);
        }

        $path = $request->path();

        foreach (self::EXEMPT_SUFFIXES as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return $next($request);
            }
        }

        $org = app('currentOrganization');

        $hasActiveLicense = LicenseActivation::where('organization_id', $org->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->exists();

        if (! $hasActiveLicense) {
            return response()->json([
                'status'     => false,
                'message'    => 'Your license has expired or is inactive. Please purchase a package to continue.',
                'data'       => [],
                'error_code' => 'license_expired',
            ], 403);
        }

        return $next($request);
    }
}
