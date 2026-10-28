<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR AI memory (spec 140–144, 318, 328): only what the person explicitly asked DORR AI to
 * remember, which they can see, edit, delete or switch off. Never filled on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_memories', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type')->comment('صاحب الذكرى: user أو provider');
            $table->unsignedBigInteger('owner_id');
            $table->text('content')->comment('المعلومة اللي المستخدم طلب صراحةً إنها تتحفظ');
            $table->foreignId('source_conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete()->comment('المحادثة اللي اتقالت فيها، لو اتحفظت من رسالة');
            $table->timestamps();
            $table->index(['owner_type', 'owner_id'], 'ai_memories_owner_index');
        });

        Schema::create('ai_owner_settings', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->boolean('memory_enabled')->default(true)->comment('الذاكرة شغالة؟ لما تتقفل مابتتحفظش ولا بتتبعت للموديل');
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id'], 'ai_owner_settings_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_owner_settings');
        Schema::dropIfExists('ai_memories');
    }
};
