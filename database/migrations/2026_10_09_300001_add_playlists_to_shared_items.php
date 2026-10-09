<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A shared playlist is a reference like a shared album: live, never a copy. */
    public function up(): void
    {
        Schema::table('shared_items', function (Blueprint $table) {
            $table->foreignId('playlist_id')->nullable()->after('track_id')->constrained()->cascadeOnDelete();
            $table->unique('playlist_id');
        });
    }

    public function down(): void
    {
        Schema::table('shared_items', function (Blueprint $table) {
            $table->dropUnique(['playlist_id']);
            $table->dropConstrainedForeignId('playlist_id');
        });
    }
};
