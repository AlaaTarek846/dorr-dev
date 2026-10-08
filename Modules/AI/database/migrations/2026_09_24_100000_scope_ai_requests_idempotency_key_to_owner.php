<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original global-uniqueness constraint on idempotency_key would
     * let one owner's client library accidentally collide with a totally
     * unrelated owner's key (both nullable columns, both generating,
     * say, a UUID independently - unlikely but not impossible, and more
     * importantly not the actual intent: idempotency is "don't repeat MY
     * request twice", not a cross-tenant namespace). Scoping the
     * uniqueness to (owner_type, owner_id, idempotency_key) matches how
     * the column is actually looked up in AiChatService::sendMessage().
     */
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropUnique('ai_requests_idempotency_key_unique');
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->unique(
                ['owner_type', 'owner_id', 'idempotency_key'],
                'ai_requests_owner_idempotency_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropUnique('ai_requests_owner_idempotency_key_unique');
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->unique(['idempotency_key'], 'ai_requests_idempotency_key_unique');
        });
    }
};
