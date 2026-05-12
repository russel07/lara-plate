<?php

namespace App\Traits;

use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\Cache;

trait HasPermissions
{
    /**
     * A user may have multiple roles.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /**
     * Determine if the user has the given permission.
     * 
     * @param string $permissionSlug
     * @return bool
     */
    public function hasPermission(array|string $permissionSlugs): bool
    {
        // 1. Super Admins bypass all permission checks globally
        if ($this->role === 'superadmin') {
            return true;
        }

        // 2. Resolve cached permissions array for the user
        $userPermissions = $this->getCachedPermissions();

        // 3. Return true if any of the requested permissions exist in their flat array
        $required = (array) $permissionSlugs;
        return !empty(array_intersect($required, $userPermissions));
    }

    /**
     * Determine if the user has any of the given permissions.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        if ($this->role === 'superadmin') {
            return true;
        }

        $userPermissions = $this->getCachedPermissions();

        return !empty(array_intersect($permissions, $userPermissions));
    }

    /**
     * Load all permissions for the user via their roles and cache it.
     * Cache is busted when roles/permissions are updated.
     */
    public function getCachedPermissions(): array
    {
        $cacheKey = 'user_permissions_' . $this->id;

        return Cache::rememberForever($cacheKey, function () {
            // Check if current organization is set to only load roles for this organization.
            // Even though the BelongsToOrganization global scope on the Role model handles this automatically,
            // we rely on the relationships to walk User -> Roles -> Permissions
            
            // Eager load roles and their permissions to avoid N+1 queries.
            $this->loadMissing('roles.permissions');

            // Map and collapse the nested permission slugs into a flat, unique array.
            return $this->roles->flatMap(function ($role) {
                return $role->permissions->pluck('slug');
            })->unique()->values()->toArray();
        });
    }

    /**
     * Assign roles to a user, ensuring they belong to current organization.
     */
    public function assignRoles(array $roleIds)
    {
        // Ensure you only sync roles that belong to current organization
        // The global scope on Role model covers this when querying Role::whereIn()
        $validRoleIds = Role::whereIn('id', $roleIds)->pluck('id')->toArray();
        
        $this->roles()->syncWithoutDetaching($validRoleIds);

        $this->clearPermissionCache();
    }

    /**
     * Clear permission cache.
     */
    public function clearPermissionCache()
    {
        Cache::forget('user_permissions_' . $this->id);
    }
}
