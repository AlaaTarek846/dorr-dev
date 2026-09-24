<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.0 requirements doc §19.3: per-case results carrying the general
     * metrics (correctness, abstention, latency, estimated cost) this
     * project's real infrastructure can actually compute today. Metrics
     * that need a human or a second AI judge (groundedness/citation
     * accuracy beyond "was a citation present", hallucination rate) are
     * deliberately NOT faked here - see AiBenchmarkRunner's docblock.
     */
    public function up(): void
    {
        Schema::create('ai_benchmark_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('run_id')->constrained('ai_benchmark_runs')->cascadeOnDelete();
            $table->foreignId('case_id')->constrained('ai_benchmark_cases')->cascadeOnDelete();

            $table->longText('actual_response')->nullable();
            $table->boolean('abstained')->default(false);
            $table->boolean('citation_present')->default(false);
            $table->decimal('correctness_score', 4, 3)->nullable()->comment('نسبة الكلمات المفتاحية المتوقعة اللي ظهرت فعلاً في الرد');
            $table->boolean('passed')->default(false);

            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('estimated_cost', 10, 6)->nullable();

            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'passed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_benchmark_results');
    }
};
