<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client-freelancer chat (FL-006) — its own tables, apart from Modules/Chat (the messenger):
 * one room per client + freelancer + what they talk about (a project, later an offer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete()->comment('مستخدم دُرر');
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('context_type', 32)->comment('project / offer — بيتحدد مع الدفعة 2');
            $table->unsignedBigInteger('context_id');
            $table->string('status', 12)->default('open')->comment('open / read_only / closed');
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 120)->nullable();
            $table->unsignedInteger('client_unread')->default(0);
            $table->unsignedInteger('freelancer_unread')->default(0);
            $table->timestamps();

            $table->unique(['client_id', 'freelancer_id', 'context_type', 'context_id'], 'freelance_conv_unique');
            $table->index(['client_id', 'last_message_at']);
            $table->index(['freelancer_id', 'last_message_at']);
            $table->index(['context_type', 'context_id']);
        });

        Schema::create('freelance_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('freelance_conversations')->cascadeOnDelete();
            $table->string('sender_type', 12)->comment('client / freelancer / system');
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->string('kind', 8)->default('text')->comment('text / image / file / system');
            $table->text('body')->nullable();
            $table->string('client_id', 64)->nullable()->comment('لو نفس الرسالة اتبعتت مرتين');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'id']);
            $table->unique(['conversation_id', 'sender_type', 'client_id'], 'freelance_msg_client_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_messages');
        Schema::dropIfExists('freelance_conversations');
    }
};
