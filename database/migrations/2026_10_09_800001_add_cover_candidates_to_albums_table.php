<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which online match the cover came from (`mb:<id>`, `itunes:<id>`) and which ones were already tried, so
     * "Find another cover" moves on to a different match instead of finding the same one again.
     */
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->string('cover_ref')->nullable()->after('cover_source');
            $table->json('cover_tried')->nullable()->after('cover_ref');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn(['cover_ref', 'cover_tried']);
        });
    }
};
