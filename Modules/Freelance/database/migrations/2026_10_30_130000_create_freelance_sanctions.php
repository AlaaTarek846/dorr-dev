<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warnings, limits and suspensions (FL-708), always issued by a person, with the appeal on the same row (one per sanction);
 * and the internal quality score and risk level of a freelancer (FL-707), kept for the admin list and its filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_sanctions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('subject_type', 12)->comment('client | freelancer');
            $table->unsignedBigInteger('subject_id');
            $table->string('type', 12)->comment('warning | chat_limit | work_limit | suspension');
            $table->string('reason', 500);
            $table->string('source', 20)->default('manual')->comment('manual | message_report | review_objection | dispute');
            $table->string('source_ref', 64)->nullable()->comment('the uuid of the report / objection / dispute');
            $table->unsignedBigInteger('issued_by');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable()->comment('null = until lifted (a suspension)');
            $table->unsignedBigInteger('lifted_by')->nullable();
            $table->string('lift_note', 500)->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->string('appeal_note', 1000)->nullable();
            $table->timestamp('appealed_at')->nullable();
            $table->string('appeal_status', 10)->nullable()->comment('open | upheld | reduced | lifted');
            $table->unsignedBigInteger('appeal_decided_by')->nullable();
            $table->string('appeal_decision_note', 500)->nullable();
            $table->timestamp('appeal_decided_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'ends_at'], 'fl_sanctions_subject');
            $table->index(['appeal_status', 'appealed_at']);
        });

        Schema::table('freelancers', function (Blueprint $table) {
            $table->unsignedTinyInteger('quality_score')->nullable()->after('status_reason');
            $table->string('risk_level', 8)->nullable()->after('quality_score')->comment('low | medium | high');
            $table->timestamp('risk_checked_at')->nullable()->after('risk_level');
            $table->index('risk_level');
        });
    }

    public function down(): void
    {
        Schema::table('freelancers', function (Blueprint $table) {
            $table->dropIndex(['risk_level']);
            $table->dropColumn(['quality_score', 'risk_level', 'risk_checked_at']);
        });
        Schema::dropIfExists('freelance_sanctions');
    }
};
