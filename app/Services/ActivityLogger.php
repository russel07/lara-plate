<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    /**
     * Record an activity log entry.
     *
     * Auto-attaches organization_id, user_id, ip_address, and user_agent
     * from the current request context.
     */
    public static function log(array $data): ?ActivityLog
    {
        $request = request();
        if ($request) {
            // Signal that a domain-specific audit entry was already emitted in this request.
            $request->attributes->set('activity_log_emitted', true);
        }

        $organizationId = $data['organization_id']
            ?? (app()->has('currentOrganization') ? app('currentOrganization')->id : null);

        $organization = $organizationId ? \App\Models\Organization::find($organizationId) : null;

        // Skip logging if the organization has disabled activity logs
        if ($organization && !$organization->activity_logs_enabled) {
            return null;
        }

        return ActivityLog::create([
            'organization_id' => $organizationId,
            'user_id'         => $data['user_id'] ?? Auth::id(),
            'action'          => $data['action'],
            'module'          => $data['module'],
            'description'     => $data['description'],
            'properties'      => isset($data['properties']) ? self::limitProperties($data['properties']) : null,
            'ip_address'      => $request?->ip(),
            'user_agent'      => $request?->userAgent(),
            'created_at'      => now(),
        ]);
    }

    /**
     * Limit the properties JSON payload size to prevent oversized rows.
     */
    private static function limitProperties(array $properties): array
    {
        $encoded = json_encode($properties);

        // Cap at ~64 KB
        if (strlen($encoded) > 65536) {
            return ['_truncated' => true, 'message' => 'Properties exceeded size limit and were omitted.'];
        }

        return $properties;
    }
}
