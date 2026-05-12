<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RbacController extends Controller
{
    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => Role::with(['organization:id,name,slug', 'permissions:id,name,slug'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json([
            'data' => Permission::orderBy('name')->get(),
        ]);
    }

    public function syncRolePermissions(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'permission_ids' => ['required', 'array', 'min:1'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role->permissions()->sync($data['permission_ids']);

        return response()->json([
            'message' => 'Role permissions updated successfully.',
            'role' => $role->load(['organization:id,name,slug', 'permissions:id,name,slug']),
        ]);
    }

    public function syncUserRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $roles = Role::whereIn('id', $data['role_ids'])->get(['id', 'organization_id']);

        if ($user->organization_id && $roles->contains(fn (Role $role) => $role->organization_id !== $user->organization_id)) {
            return response()->json([
                'message' => 'All assigned roles must belong to the user organization.',
            ], 422);
        }

        $user->roles()->sync($data['role_ids']);

        return response()->json([
            'message' => 'User roles updated successfully.',
            'user' => $user->load(['organization:id,name,slug', 'roles:id,name,organization_id']),
        ]);
    }
}