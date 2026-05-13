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
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('avatar_media_id')->nullable()->after('password');
            $table->foreign('avatar_media_id')->references('id')->on('media')->onDelete('set null');
            $table->index('avatar_media_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['avatar_media_id']);
            $table->dropIndex(['avatar_media_id']);
            $table->dropColumn('avatar_media_id');
        });
    }
};
