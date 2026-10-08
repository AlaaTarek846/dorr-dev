<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_knowledge_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('knowledge_source_id')
                ->constrained('ai_knowledge_sources')
                ->cascadeOnDelete()
                ->comment('مصدر المعرفة اللي القطعة دي جزء منه');

            $table->unsignedInteger('chunk_index')->default(0)->comment('ترتيب القطعة دي داخل المصدر الأصلي');
            $table->string('content_ref')->comment('مكان تخزين القطعة في فهرس البحث (مش المحتوى نفسه في الجدول)');
            $table->unsignedInteger('token_count')->default(0)->comment('عدد التوكنز اللي القطعة دي هتاخدها لو اتبعتت للموديل');
            $table->boolean('searchable')->default(true)->comment('هل القطعة دي متاحة للبحث دلوقتي؟');

            $table->timestamps();

            $table->index(['knowledge_source_id', 'chunk_index'], 'ai_knowledge_chunks_source_index_index');
            $table->index('searchable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_chunks');
    }
};
