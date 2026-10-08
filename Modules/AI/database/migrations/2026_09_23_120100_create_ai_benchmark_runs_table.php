<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_benchmark_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('model_key')->nullable();

            $table->string('status')->default('running')->comment('running, completed, failed');
            $table->unsignedInteger('total_cases')->default(0);
            $table->unsignedInteger('passed_cases')->default(0);
            $table->unsignedInteger('abstained_cases')->default(0);

            // v2.0 doc §19.5: 95% is never announced without a proper,
            // sample-sized, reviewed test pass - this stores the raw
            // pass rate computed from actual results, never a claim.
            $table->decimal('pass_rate', 5, 2)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_benchmark_runs');
    }
};
