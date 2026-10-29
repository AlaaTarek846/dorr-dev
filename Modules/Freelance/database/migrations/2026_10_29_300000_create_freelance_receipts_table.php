<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-511: a receipt for every money movement of a part, for the side it concerns — numbered in order per country and year
 * (FR-SA-2026-000123), written in the same transaction as the movement, never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_receipt_counters', function (Blueprint $table) {
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_seq')->default(0);
            $table->primary(['country_id', 'year']);
        });

        Schema::create('freelance_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 32)->unique();
            $table->foreignId('country_id')->constrained('countries');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('seq');
            $table->string('party', 12)->comment('client · freelancer');
            $table->string('kind', 20)->comment('funded · paid · refunded · settled · earned');
            $table->string('reason', 20)->nullable()->comment('approved · auto_approved · ended · dispute · cancelled');
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->foreignId('milestone_id')->constrained('freelance_milestones')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('amount_minor')->default(0)->comment('the part (or the share of it) the movement is about');
            $table->unsignedBigInteger('fee_minor')->default(0)->comment('client: the service fee · freelancer: the commission');
            $table->unsignedBigInteger('total_minor')->default(0)->comment('client: held / paid / given back · freelancer: the net');
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->unique(['country_id', 'year', 'seq']);
            $table->unique(['milestone_id', 'party', 'kind']);
            $table->index(['client_id', 'id']);
            $table->index(['freelancer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_receipts');
        Schema::dropIfExists('freelance_receipt_counters');
    }
};
