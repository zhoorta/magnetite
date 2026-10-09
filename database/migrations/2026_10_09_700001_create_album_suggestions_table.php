<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suggestions for folders of tracks without an album tag (docs/identify-albums.md), kept until the owner accepts
     * or dismisses them. `tracks.identified` marks tracks whose album, title and numbers were confirmed by the owner:
     * a scan refreshes their technical fields but no longer reads those from the tags.
     */
    public function up(): void
    {
        Schema::create('album_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('folder'); // the folder (or the parent of CD1/CD2 folders) the tracks sit in
            $table->json('guess');
            $table->json('candidates');
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'folder']);
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->boolean('identified')->default(false)->after('album_id');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('identified');
        });
        Schema::dropIfExists('album_suggestions');
    }
};
