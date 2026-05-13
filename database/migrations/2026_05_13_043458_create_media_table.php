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
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('file_name');
            $table->string('file_path');
            $table->enum('file_type', ['image', 'video', 'audio', 'pdf', 'document']);
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('alternate_text')->nullable();
            $table->string('title')->nullable();
            $table->string('caption')->nullable();
            $table->string('description')->nullable();
            $table->string('hash', 64)->unique();
            $table->string('disk', 32)->default('public');
            $table->enum('visibility', ['public', 'private'])->default('public');
            $table->timestamps();

            $table->index(['organization_id', 'file_type']);
            $table->index(['organization_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
