<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contacts (synced from the phone, added by number, or by QR), blocks, privacy and folders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('name', 150);
            $table->string('phone', 32)->comment('E.164, e.g. +966500000001');
            // Filled when the phone belongs to someone registered.
            $table->string('contact_type', 32)->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->string('source', 16)->default('device')->comment('device | manual | qr');
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'phone'], 'chat_contacts_unique');
            $table->index(['contact_type', 'contact_id'], 'chat_contacts_contact_index');
            $table->index('phone');
        });

        Schema::create('chat_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('blocker_type', 32);
            $table->unsignedBigInteger('blocker_id');
            $table->string('blocked_type', 32);
            $table->unsignedBigInteger('blocked_id');
            $table->timestamps();

            $table->unique(['blocker_type', 'blocker_id', 'blocked_type', 'blocked_id'], 'chat_blocks_unique');
            $table->index(['blocked_type', 'blocked_id'], 'chat_blocks_blocked_index');
        });

        Schema::create('chat_privacy_settings', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('last_seen', 16)->default('everyone')->comment('everyone | contacts | nobody, also hides online');
            $table->string('profile_photo', 16)->default('everyone');
            $table->boolean('read_receipts')->default(true)->comment('off = nobody sees my blue ticks, and I see nobody else');
            $table->string('who_can_message', 16)->default('everyone')->comment('everyone = strangers land in message requests');
            $table->string('who_can_add_to_groups', 16)->default('everyone');
            $table->string('who_can_call', 16)->default('everyone');
            $table->boolean('block_screenshots')->default(false);
            $table->boolean('notification_preview')->default(true)->comment('show the message text in the push');
            $table->string('qr_token', 40)->nullable()->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id'], 'chat_privacy_owner_unique');
        });

        Schema::create('chat_folders', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('name', 50);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'name'], 'chat_folders_unique');
        });

        Schema::create('chat_folder_conversations', function (Blueprint $table) {
            $table->foreignId('folder_id')->constrained('chat_folders')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();

            $table->primary(['folder_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_folder_conversations');
        Schema::dropIfExists('chat_folders');
        Schema::dropIfExists('chat_privacy_settings');
        Schema::dropIfExists('chat_blocks');
        Schema::dropIfExists('chat_contacts');
    }
};
