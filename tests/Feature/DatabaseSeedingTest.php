<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_admin_org_role_and_pivots_exist_after_seeding(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = DB::table('users')->where('email', 'admin@example.com')->first();
        $this->assertNotNull($admin, 'Default admin user was not seeded.');

        $organization = DB::table('organizations')
            ->where('slug', 'default-organization')
            ->first();
        $this->assertNotNull($organization, 'Default organization was not seeded.');
        $this->assertSame($admin->id, $organization->created_by);

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'organization_id' => $organization->id,
        ]);

        $role = DB::table('roles')
            ->where('organization_id', $organization->id)
            ->where('name', 'Superadmin')
            ->first();
        $this->assertNotNull($role, 'Superadmin role was not seeded for the default organization.');

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $admin->id,
            'role_id' => $role->id,
        ]);

        $permissionsCount = DB::table('permissions')->count();
        $rolePermissionsCount = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->count();

        $this->assertGreaterThan(0, $permissionsCount, 'Permissions were not seeded.');
        $this->assertSame(
            $permissionsCount,
            $rolePermissionsCount,
            'Superadmin role should be linked to all seeded permissions.'
        );
    }
}
