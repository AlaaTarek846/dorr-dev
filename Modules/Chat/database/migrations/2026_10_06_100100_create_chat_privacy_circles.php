<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy circles (spec 98–103): my own grouping of chats — "Friends", "Work", "Family" — each
 * with how much its notifications reveal (P0 everything … P4 nothing but a count), an optional
 * stand-in name (shown instead of the real one), whether its chats leave the main list (seen only
 * inside the circle), and a lock (fingerprint on this phone before it opens). A circle is a way of
 * showing my chats, never a permission: nobody else knows it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_privacy_circles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('name', 60);
            $table->string('masked_name', 60)->nullable()->comment('shown instead of the name (notifications, the circle chip)');
            $table->string('emoji', 16)->nullable();
            $table->string('color', 9)->nullable();
            $table->string('disclosure', 10)->default('circle')->comment('all (P0) | name (P1) | circle (P2) | none (P3) | hidden (P4)');
            $table->boolean('hide_from_list')->default(true)->comment('its chats show only inside the circle');
            $table->boolean('locked')->default(false)->comment('fingerprint / screen lock before it opens');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::table('chat_participants', function (Blueprint $table) {
            $table->foreignId('privacy_circle_id')->nullable()->constrained('chat_privacy_circles')->nullOnDelete()->comment('my circle for this chat');
        });
    }

    public function down(): void
    {
        Schema::table('chat_participants', fn (Blueprint $table) => $table->dropConstrainedForeignId('privacy_circle_id'));
        Schema::dropIfExists('chat_privacy_circles');
    }
};
