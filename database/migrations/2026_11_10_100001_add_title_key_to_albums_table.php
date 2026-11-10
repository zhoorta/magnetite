<?php

use App\Support\AlbumKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The title as albums are compared by (App\Support\AlbumKey), so the scanner finds an album whatever the case or quotes of the tag. */
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->string('title_key')->nullable()->after('title');
            $table->index(['user_id', 'title_key']);
        });

        DB::table('albums')->orderBy('id')->each(fn ($album) => DB::table('albums')->where('id', $album->id)->update(['title_key' => AlbumKey::title($album->title)]));
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'title_key']);
            $table->dropColumn('title_key');
        });
    }
};
