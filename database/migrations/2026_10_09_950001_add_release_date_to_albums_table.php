<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The release date as far as it is known: `2001`, `2001-03` or `2001-03-12` (MusicBrainz gives partial dates too). `year` stays, derived from it. */
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->string('release_date', 10)->nullable()->after('year');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn('release_date');
        });
    }
};
