<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A user's interface choices for the library: how much detail (`info`) and whether covers are pixelated (`pixel`). Null: the defaults. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('ui')->nullable()->after('meters');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ui');
        });
    }
};
