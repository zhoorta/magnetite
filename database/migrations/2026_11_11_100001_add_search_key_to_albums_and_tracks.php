<?php

use App\Support\AlbumKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Title and artist folded for searching (App\Support\AlbumKey::searchKey): lower case, no accents, one text to look in. */
    public function up(): void
    {
        foreach (['albums', 'tracks'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('search_key', 600)->nullable();
            });

            DB::table($table)->orderBy('id')->each(fn ($row) => DB::table($table)->where('id', $row->id)->update(['search_key' => mb_substr(AlbumKey::searchKey($row->title, $row->artist), 0, 600)]));
        }
    }

    public function down(): void
    {
        foreach (['albums', 'tracks'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('search_key'));
        }
    }
};
