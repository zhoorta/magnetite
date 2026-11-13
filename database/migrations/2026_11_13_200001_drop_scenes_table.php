<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /** Scenes (a picture as a meter) were removed; their table and the stored pictures go too. The code is on the `scenes` branch. */
    public function up(): void
    {
        Schema::dropIfExists('scenes');
        Storage::disk('local')->deleteDirectory('scenes');
    }

    public function down(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('file', 44);
            $table->json('settings')->nullable();
            $table->json('hotspots')->nullable();
            $table->timestamps();
        });
    }
};
