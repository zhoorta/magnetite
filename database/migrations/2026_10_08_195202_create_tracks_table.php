<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path')->unique(); // relative to the music folder
            $table->string('title');
            $table->string('artist')->nullable();
            $table->unsignedSmallInteger('track_number')->nullable();
            $table->unsignedSmallInteger('disc_number')->nullable();
            $table->float('duration')->nullable(); // seconds
            $table->string('codec')->nullable();
            $table->string('mime');
            $table->unsignedInteger('bitrate')->nullable();
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('mtime');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
