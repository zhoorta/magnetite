<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The owner's own folders ("Jazz", "Classical", subfolders…) for ordering albums. Only a view of the library: the files do not move. */
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });
        Schema::dropIfExists('folders');
    }
};
