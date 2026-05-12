<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $role = DB::table('roles')->where('name', 'Superadmin')->first();

        if (! $role) {
            return;
        }

        $permissionIds = DB::table('permissions')->pluck('id');

        $rows = $permissionIds->map(fn ($permissionId) => [
            'role_id' => $role->id,
            'permission_id' => $permissionId,
        ])->all();

        DB::table('role_permissions')->insertOrIgnore($rows);
    }
}
