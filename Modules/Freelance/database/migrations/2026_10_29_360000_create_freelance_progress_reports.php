<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-605: the optional progress reports on an agreement - how often the client asked for one, and the reports the freelancer sent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_agreements', function (Blueprint $table) {
            $table->unsignedSmallInteger('report_every_days')->nullable()->after('duration_days')->comment('null = no reports asked');
            $table->timestamp('next_report_due_at')->nullable()->after('report_every_days');
            $table->timestamp('last_report_at')->nullable()->after('next_report_due_at');
            $table->timestamp('report_reminded_at')->nullable()->after('last_report_at')->comment('the freelancer was reminded for this due date');
            $table->timestamp('report_late_notified_at')->nullable()->after('report_reminded_at')->comment('the client was told it is late for this due date');
        });

        Schema::create('freelance_progress_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->text('note');
            $table->unsignedTinyInteger('percent')->nullable();
            $table->timestamps();

            $table->index(['agreement_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_progress_reports');
        Schema::table('freelance_agreements', function (Blueprint $table) {
            $table->dropColumn(['report_every_days', 'next_report_due_at', 'last_report_at', 'report_reminded_at', 'report_late_notified_at']);
        });
    }
};
