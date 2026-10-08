<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            // Result of `magnetite:analyze`: the full measurement as JSON, plus the few values worth querying.
            $table->json('analysis')->nullable();
            $table->unsignedSmallInteger('analysis_version')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->unsignedTinyInteger('dr')->nullable();
            $table->string('pcm_sha1', 40)->nullable()->index(); // hash of the decoded audio, for exact duplicates
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn(['analysis', 'analysis_version', 'analyzed_at', 'dr', 'pcm_sha1']);
        });
    }
};
