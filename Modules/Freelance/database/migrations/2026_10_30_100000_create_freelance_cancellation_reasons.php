<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-607: why a project or a part was called off, as stated by the one who did it, and who that points at (staff only; no effect on money). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_cancellation_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->unsignedBigInteger('agreement_id')->nullable();
            $table->unsignedBigInteger('milestone_id')->nullable()->comment('null = the whole project');
            $table->string('actor_type', 12)->comment('client | freelancer');
            $table->unsignedBigInteger('actor_id');
            $table->string('reason_code', 30);
            $table->string('responsibility', 12);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['responsibility', 'created_at']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_cancellation_reasons');
    }
};
