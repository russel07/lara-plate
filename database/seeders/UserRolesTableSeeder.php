<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserRolesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = DB::table('users')->where('email', 'admin@example.com')->first();
        $role = DB::table('roles')->where('name', 'Superadmin')->first();

        if (! $user || ! $role) {
            return;
        }

        DB::table('user_roles')->insertOrIgnore([
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
            ],
        ]);
    }
}
