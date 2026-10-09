<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The record label of an album: read from the tags (publisher / label) when a file has one, or typed by the owner. */
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->string('label')->nullable()->after('year');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
