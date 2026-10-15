<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call links (WhatsApp list 72): a link anyone with it can tap to join one voice / video room —
 * no chat needed, nobody is rung. Valid until it expires or its owner cancels it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_call_links', function (Blueprint $table) {
            $table->id();
            $table->string('token', 40)->unique();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->string('type', 10); // audio | video
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_joined_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_call_links');
    }
};
