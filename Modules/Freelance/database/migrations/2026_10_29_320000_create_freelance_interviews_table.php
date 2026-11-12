<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-310: a short interview between a client and a freelancer before the agreement. One side proposes a time in their chat room,
 * the other confirms or declines; a confirmed one is put in the client's Dorr calendar and both are reminded before it starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_interviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('freelance_conversations')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->index()->comment('the context of the room');
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('proposed_by', 12)->comment('client / freelancer');
            $table->dateTime('starts_at');
            $table->unsignedSmallInteger('duration_minutes')->default(15);
            $table->string('note', 300)->nullable();
            $table->string('status', 12)->default('proposed')->comment('proposed · confirmed · declined · cancelled · superseded');
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('calendar_item_id')->nullable()->comment('the item in the client calendar');
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'status']);
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_interviews');
    }
};
