<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The meters a user chose, as a list of meter ids. Null: never chose, so they get the default one. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('meters')->nullable()->after('quota_mb');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('meters');
        });
    }
};
