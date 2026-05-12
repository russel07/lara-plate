<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        
        // 1. permissions (global)
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Create Course"
            $table->string('slug')->unique(); // e.g., "create_course", used for lookups
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // 2. roles (tenant specific)
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // FK is added in the organizations migration because this migration runs first.
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
            
            // Critical: Ensure role names are unique ONLY within a specific organization
            // E.g., both Acme and Metrouni can have an "Admin" role
            $table->unique(['organization_id', 'name']);
        });

        // 3. role_permissions (pivot)
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            
            // Composite primary key helps performance and acts as a unique constraint
            $table->primary(['role_id', 'permission_id']);
        });

        // 4. user_roles (pivot)
        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            
            // Composite primary key
            $table->primary(['user_id', 'role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
