<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every album and track belongs to a user, paths are unique per user, and the shared area holds references.
     * Rows from before this migration have no owner; with exactly one user they all become theirs, otherwise
     * `php artisan magnetite:claim {username}` hands them over.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->unsignedInteger('quota_mb')->nullable()->after('is_admin');
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->dropUnique(['path']);
            $table->unique(['user_id', 'path']);
        });

        Schema::create('shared_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // who shares it
            $table->foreignId('album_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique('album_id');
            $table->unique('track_id');
        });

        $users = DB::table('users')->pluck('id');
        if ($users->count() === 1) {
            DB::table('albums')->update(['user_id' => $users->first()]);
            DB::table('tracks')->update(['user_id' => $users->first()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_items');

        Schema::table('tracks', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'path']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique('path');
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'quota_mb']);
        });
    }
};
