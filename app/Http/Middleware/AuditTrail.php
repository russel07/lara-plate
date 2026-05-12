<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuditTrail
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Skip preflight calls.
        if ($request->method() === 'OPTIONS') {
            return $response;
        }

        // Track authenticated actions for end-to-end user session auditing.
        $user = $request->user();
        if (!$user) {
            return $response;
        }

        // De-duplicate when a domain-specific controller/service already emitted an audit log.
        if ($request->attributes->get('activity_log_emitted') === true) {
            return $response;
        }

        $route = $request->route();
        $routeUri = $route?->uri() ?? $request->path();

        // Reduce audit noise for low-value reads while preserving full write coverage.
        if ($request->isMethod('GET') && $this->shouldSkipReadAudit($routeUri)) {
            return $response;
        }

        $profile = $this->resolveAuditProfile($request, $routeUri, $response);
        $properties = $this->buildAuditProperties($request, $routeUri, $response);

        ActivityLogger::log([
            'action' => $profile['action'],
            'module' => $profile['module'],
            'description' => $profile['description'],
            'properties' => $properties,
        ]);

        return $response;
    }

    /**
     * Determine whether a read endpoint should be excluded from generic audit logging.
     */
    private function shouldSkipReadAudit(string $routeUri): bool
    {
        $normalized = trim($routeUri, '/');

        $skipPatterns = [
            'me',
            'me/permissions',
            'organization/me',
            'central/me',
        ];

        return Str::is($skipPatterns, $normalized);
    }

    /**
     * Build a readable profile for generic middleware logs.
     */
    private function resolveAuditProfile(Request $request, string $routeUri, Response $response): array
    {
        $method = strtoupper($request->method());
        $normalizedUri = trim($routeUri, '/');
        $user = $request->user();
        $actorLabel = $user?->email ?? ('user#' . (string) $user?->id);
        $statusCode = $response->getStatusCode();
        $outcome = $statusCode < 400 ? 'succeeded' : 'failed';

        $profiles = [
            ['method' => 'PUT', 'pattern' => 'organization/me', 'action' => 'organization_settings_updated', 'module' => 'organization', 'description' => "Organization settings update {$outcome} by {$actorLabel}"],
            ['method' => 'POST', 'pattern' => 'organization/invite', 'action' => 'organization_user_invited', 'module' => 'organization', 'description' => "Organization invitation {$outcome} by {$actorLabel}"],
            ['method' => 'POST', 'pattern' => 'organization/toggle-activity-logs', 'action' => 'organization_activity_logs_toggled', 'module' => 'organization', 'description' => "Activity log setting toggle {$outcome} by {$actorLabel}"],
            ['method' => 'PUT', 'pattern' => 'profile-settings', 'action' => 'profile_settings_updated', 'module' => 'authentication', 'description' => "Profile settings update {$outcome} by {$actorLabel}"],
            ['method' => 'POST', 'pattern' => 'change-password', 'action' => 'password_changed', 'module' => 'authentication', 'description' => "Password change {$outcome} by {$actorLabel}"],
            ['method' => 'POST', 'pattern' => 'verify-otp', 'action' => 'otp_verified', 'module' => 'authentication', 'description' => "OTP verification {$outcome} for {$actorLabel}"],
            ['method' => 'POST', 'pattern' => 'resend-otp', 'action' => 'otp_resent', 'module' => 'authentication', 'description' => "OTP resend {$outcome} for {$actorLabel}"],
            ['method' => 'POST', 'pattern' => 'logout', 'action' => 'logout_request_processed', 'module' => 'authentication', 'description' => "Logout request {$outcome} for {$actorLabel}"],
        ];

        foreach ($profiles as $profile) {
            if ($method === $profile['method'] && Str::is($profile['pattern'], $normalizedUri)) {
                return [
                    'action' => $profile['action'],
                    'module' => $profile['module'],
                    'description' => $profile['description'],
                ];
            }
        }

        $defaultAction = strtolower($method . '_' . str_replace(['/', '{', '}'], '_', $normalizedUri));
        $defaultAction = preg_replace('/_+/', '_', $defaultAction) ?? $defaultAction;

        return [
            'action' => $defaultAction,
            'module' => $this->resolveModule($normalizedUri),
            'description' => sprintf('%s %s %s for %s', $method, $normalizedUri, $outcome, $actorLabel),
        ];
    }

    /**
     * Build rich metadata for generic middleware logs.
     */
    private function buildAuditProperties(Request $request, string $routeUri, Response $response): array
    {
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'current_password',
            'token',
            'otp',
        ];

        // Use input() instead of except() so uploaded file objects are never re-hydrated
        // from temporary paths that may have been moved/deleted by controllers.
        $input = Arr::except($request->input(), $sensitiveKeys);
        $routeParameters = $request->route()?->parameters() ?? [];
        $queryParameters = $request->query();
        $organization = app()->has('currentOrganization') ? app('currentOrganization') : null;
        $statusCode = $response->getStatusCode();

        return [
            'event_source' => 'middleware_generic',
            'endpoint_template' => trim($routeUri, '/'),
            'endpoint_actual' => trim($request->path(), '/'),
            'http_method' => strtoupper($request->method()),
            'status_code' => $statusCode,
            'outcome' => $statusCode < 400 ? 'success' : 'failure',
            'input_keys' => array_values(array_keys($input)),
            'query_keys' => array_values(array_keys($queryParameters)),
            'route_params' => $routeParameters,
            'actor' => [
                'id' => $request->user()?->id,
                'email' => $request->user()?->email,
                'role' => $request->user()?->role,
                'organization_id' => $request->user()?->organization_id,
            ],
            'tenant' => [
                'id' => $organization?->id,
                'slug' => $organization?->slug,
            ],
        ];
    }

    /**
     * Infer module from URI for fallback generic events.
     */
    private function resolveModule(string $normalizedUri): string
    {
        if ($normalizedUri === '') {
            return 'system';
        }

        $segments = explode('/', $normalizedUri);
        if (($segments[0] ?? null) === 'central') {
            return $segments[1] ?? 'central';
        }

        return $segments[0] ?? 'system';
    }
}
