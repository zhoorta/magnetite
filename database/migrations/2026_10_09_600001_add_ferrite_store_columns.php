<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Where the library lives when it is not on the local disk: {driver, url, token}, stored encrypted.
            $table->text('store')->nullable();
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->string('store_key')->nullable(); // the remote store's id of the file
            $table->char('store_hash', 64)->nullable(); // the remote store's sha256 of the file
        });
    }

    public function down(): void
    {
        Schema::table('tracks', fn (Blueprint $table) => $table->dropColumn(['store_key', 'store_hash']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('store'));
    }
};
