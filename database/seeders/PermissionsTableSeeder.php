<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        DB::table('permissions')->upsert([
            ['name' => 'Manage Users', 'slug' => 'manage_users', 'description' => 'Ability to manage user accounts', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Manage Roles', 'slug' => 'manage_roles', 'description' => 'Ability to manage user roles and permissions', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'View Users', 'slug' => 'view_users', 'description' => 'Ability to view user information', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Create Users', 'slug' => 'create_users', 'description' => 'Ability to create new user accounts', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Edit Users', 'slug' => 'edit_users', 'description' => 'Ability to edit existing user accounts', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Delete Users', 'slug' => 'delete_users', 'description' => 'Ability to delete user accounts', 'created_at' => $now, 'updated_at' => $now],
        ], ['slug'], ['name', 'description', 'updated_at']);
    }
}
