<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9: mirrors ai_request_citations exactly (same audit purpose -
 * "what evidence did this AI reply actually rely on"), but FK'd to
 * ai_files/ai_file_chunks instead of ai_knowledge_sources/
 * ai_knowledge_chunks - kept as its own table rather than overloading
 * ai_request_citations with a second, parallel pair of nullable FKs,
 * since the two features (admin-curated Knowledge Base vs. a user's
 * own uploaded files) have no other shared semantics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_file_citations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('request_id')->constrained('ai_requests')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('ai_files')->cascadeOnDelete();
            $table->foreignId('chunk_id')->constrained('ai_file_chunks')->cascadeOnDelete();

            $table->unsignedInteger('position')->default(0)->comment('ترتيب القطعة دي في قائمة الاستشهادات لهذا الطلب');
            $table->text('excerpt');
            $table->float('relevance_score');
            $table->string('retrieval_method', 20)->comment('keyword|semantic|hybrid|exact');
            $table->json('source_reference')->nullable();

            $table->timestamps();

            $table->index('request_id');
            $table->index('file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_file_citations');
    }
};
