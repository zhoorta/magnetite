<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a downloaded cover came from (null: it came from the user's own files) and when the online lookup last
     * ran, so albums nobody has a cover for are not searched again on every run.
     */
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->string('cover_source')->nullable()->after('cover');
            $table->timestamp('cover_checked_at')->nullable()->after('cover_source');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn(['cover_source', 'cover_checked_at']);
        });
    }
};
