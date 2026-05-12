<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        DB::table('users')->upsert([
            [
                'name' => 'System Admin',
                'email' => 'admin@example.com',
                'password' => Hash::make('password'),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['email'], ['name', 'password', 'status', 'updated_at']);

        $user = DB::table('users')->where('email', 'admin@example.com')->first();

        DB::table('organizations')->upsert([
            [
                'name' => 'Default Organization',
                'slug' => 'default-organization',
                'created_by' => $user->id,
                'activity_logs_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug'], ['name', 'created_by', 'activity_logs_enabled', 'updated_at']);

        $organization = DB::table('organizations')->where('slug', 'default-organization')->first();

        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'organization_id' => $organization->id,
                'updated_at' => $now,
            ]);
    }
}
