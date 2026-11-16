<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A row with a user_id is a password reset link for that user, not an invitation.
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('label')->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
