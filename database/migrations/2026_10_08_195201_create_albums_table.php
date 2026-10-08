<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('albums', function (Blueprint $table) {
            $table->id();
            $table->string('artist')->nullable();
            $table->string('title');
            // Folder (relative to the music folder) of albums that have no album-artist tag; part of their identity.
            $table->string('folder')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            // File name under the covers disk; the image is derived data, the music folder stays untouched.
            $table->string('cover')->nullable();
            $table->timestamps();

            $table->index(['title', 'artist', 'folder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('albums');
    }
};
