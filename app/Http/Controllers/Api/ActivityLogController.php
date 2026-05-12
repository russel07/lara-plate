<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListActivityLogsRequest;
use App\Models\ActivityLog;

class ActivityLogController extends Controller
{
    /**
     * Return paginated, filterable activity logs.
     *
     * - Tenant context (organization present): scoped to that organization.
     * - Central context (no organization / superadmin): returns logs across ALL organizations.
     */
    public function index(ListActivityLogsRequest $request)
    {
        $validated = $request->validated();

        $isTenant = app()->has('currentOrganization');

        $page      = (int) ($validated['page'] ?? 1);
        $limit     = (int) ($validated['limit'] ?? 20);
        $sortOrder = $validated['sort_order'] ?? 'desc';

        $query = ActivityLog::query()->with('user:id,name,email');

        if ($isTenant) {
            // Organization-scoped logs
            $query->where('organization_id', app('currentOrganization')->id);
        } else {
            // Central / superadmin — load organization name for context
            $query->with('organization:id,name');

            // Optional: filter by a specific organization
            if (!empty($validated['organization_id'])) {
                $query->where('organization_id', (int) $validated['organization_id']);
            }
        }

        // Filter by module
        if (!empty($validated['module'])) {
            $query->where('module', $validated['module']);
        }

        // Filter by action
        if (!empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        // Filter by user
        if (!empty($validated['user_id'])) {
            $query->where('user_id', (int) $validated['user_id']);
        }

        // Date range
        if (!empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }
        if (!empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        // Search in description
        if (!empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where('description', 'like', '%' . $search . '%');
        }

        $query->orderBy('created_at', $sortOrder);

        $paginator = $query->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'status'  => true,
            'message' => 'Activity logs fetched successfully.',
            'data'    => $paginator->getCollection()->map(function (ActivityLog $log) use ($isTenant) {
                $entry = [
                    'id'          => $log->id,
                    'user'        => $log->user?->name,
                    'user_email'  => $log->user?->email,
                    'action'      => $log->action,
                    'module'      => $log->module,
                    'description' => $log->description,
                    'properties'  => $log->properties,
                    'ip_address'  => $log->ip_address,
                    'created_at'  => $log->created_at?->format('Y-m-d H:i:s'),
                ];

                // Include organization info for superadmin / central view
                if (!$isTenant) {
                    $entry['organization'] = $log->organization?->name;
                    $entry['organization_id'] = $log->organization_id;
                }

                return $entry;
            })->values(),
            'meta' => [
                'total_records'  => $paginator->total(),
                'per_page'       => $paginator->perPage(),
                'current_page'   => $paginator->currentPage(),
                'total_pages'    => $paginator->lastPage(),
                'has_more_pages' => $paginator->hasMorePages(),
            ],
        ]);
    }
}
