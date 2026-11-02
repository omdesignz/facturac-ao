<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where Apple delivers notifications for one signed-in phone.
 *
 * The row hangs off the phone's access token, so signing out, revoking the
 * session from another device or letting it expire stops the notifications
 * with it. The Apple token is unique: a phone that someone else signs in to
 * moves to them rather than telling two people the same news.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token', 200)->unique();
            $table->string('environment', 16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
