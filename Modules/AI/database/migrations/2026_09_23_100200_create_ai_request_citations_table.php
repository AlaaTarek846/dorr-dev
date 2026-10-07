<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for section 5.6: every knowledge chunk actually
     * retrieved and injected as evidence for a given AI request, together
     * with its source, position and excerpt - so an answer's citations
     * can always be traced back to what was retrieved.
     */
    public function up(): void
    {
        Schema::create('ai_request_citations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('request_id')
                ->constrained('ai_requests')
                ->cascadeOnDelete()
                ->comment('طلب الـ AI اللي الاستشهاد ده اتضاف كدليل ليه');

            $table->foreignId('knowledge_source_id')->nullable()
                ->constrained('ai_knowledge_sources')
                ->nullOnDelete()
                ->comment('مصدر المعرفة الأصلي');

            $table->foreignId('knowledge_chunk_id')->nullable()
                ->constrained('ai_knowledge_chunks')
                ->nullOnDelete()
                ->comment('القطعة بالتحديد اللي اتسترجعت');

            $table->unsignedInteger('position')->default(0)
                ->comment('ترتيب/موضع القطعة داخل المصدر الأصلي (chunk_index)');

            $table->text('excerpt')->nullable()
                ->comment('المقتطف اللي اتحط في الـ prompt كدليل');

            $table->decimal('relevance_score', 4, 3)->nullable()
                ->comment('درجة الصلة اللي محرك الاسترجاع الهجين حسبها لهذه القطعة بالنسبة للسؤال');

            $table->timestamps();

            $table->index(['request_id'], 'ai_request_citations_request_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_request_citations');
    }
};
