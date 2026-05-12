<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RbacApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_admin_can_manage_rbac_and_assign_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $adminToken = $this->postJson('/api/central/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->json('token');

        $this->assertNotEmpty($adminToken);

        $permissionsResponse = $this->withToken($adminToken)
            ->getJson('/api/rbac/permissions');

        $permissionsResponse->assertOk();

        $organizationId = DB::table('organizations')->where('slug', 'default-organization')->value('id');

        $user = User::factory()->create([
            'organization_id' => $organizationId,
        ]);

        $assignRoleResponse = $this->withToken($adminToken)
            ->postJson('/api/rbac/users/' . $user->id . '/roles', [
                'role_ids' => [DB::table('roles')->where('name', 'Superadmin')->value('id')],
            ]);

        $assignRoleResponse->assertOk();

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('name', 'Superadmin')->value('id'),
        ]);
    }

    public function test_basic_user_is_blocked_from_rbac_routes(): void
    {
        $this->seed(DatabaseSeeder::class);

        $organizationId = DB::table('organizations')->where('slug', 'default-organization')->value('id');

        $basicUser = User::factory()->create([
            'organization_id' => $organizationId,
        ]);

        $basicToken = $basicUser->createToken('basic-test-token')->plainTextToken;

        $this->withToken($basicToken)
            ->getJson('/api/rbac/permissions')
            ->assertForbidden();
    }
}