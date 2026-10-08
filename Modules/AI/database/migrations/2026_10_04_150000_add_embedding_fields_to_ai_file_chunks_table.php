<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 (doc S9/S10): closes a gap Phase 8 deliberately left open -
 * AiIndexingEngine::embedChunks() computed an embedding vector when
 * `indexing.auto_embed` was on, but had nowhere to persist it. Phase 9
 * now writes the vector into the chunk's own content_ref JSON file
 * (same file, alongside "content" - mirroring ai_knowledge_chunks'
 * existing content+embedding JSON shape exactly), and these new
 * columns track the embedding's LIFECYCLE on the relational row so
 * retrieval can filter/report on it without reading every chunk's file
 * off disk first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_file_chunks', function (Blueprint $table) {
            $table->string('embedding_status', 20)->default('pending')->after('status')
                ->comment('pending|processing|embedded|failed|stale|deleted - independent of the chunk\'s own indexing status');
            $table->string('embedding_model', 100)->nullable()->after('embedding_status');
            $table->string('embedding_provider', 60)->nullable()->after('embedding_model');
            $table->timestamp('embedded_at')->nullable()->after('embedding_provider');

            $table->index(['file_id', 'embedding_status']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_file_chunks', function (Blueprint $table) {
            $table->dropIndex(['file_id', 'embedding_status']);
            $table->dropColumn(['embedding_status', 'embedding_model', 'embedding_provider', 'embedded_at']);
        });
    }
};
