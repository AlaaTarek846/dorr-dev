<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One audit trail for sensitive admin and system actions (docs/rides-structure.md §9 — R-195,
 * D-351): what changed, by whom, and the values before and after. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 16)->comment('admin / system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 64)->comment('مثلًا ride_policy.updated أو driver.approved');
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable()->comment('القيمة قبل');
            $table->json('after')->nullable()->comment('القيمة بعد');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
