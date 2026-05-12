<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $organization = DB::table('organizations')->where('slug', 'default-organization')->first();

        if (! $organization) {
            return;
        }

        $now = now();

        DB::table('roles')->upsert([
            [
                'organization_id' => $organization->id,
                'name' => 'Superadmin',
                'description' => 'Riselms Central Administrator with full access',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['organization_id', 'name'], ['description', 'updated_at']);
    }
}
