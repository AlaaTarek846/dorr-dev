<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-401: the agreement both sides approve after the client chose an offer — what the work is, its price and days, and a snapshot
 * of the policy (commission, fees, review days, free revisions) frozen when it starts. FL-406: every approval on record, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_agreements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained('freelance_offers')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->text('scope');
            $table->text('includes')->nullable();
            $table->text('excludes')->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->string('currency_code', 3);
            $table->unsignedSmallInteger('duration_days');
            $table->json('policy')->comment('the policy values when it was made');
            $table->json('money')->comment('price, client fee and total, commission and net, worked out from `policy`');
            $table->string('status', 20)->default('draft')->comment('draft · pending_approval · active · declined · cancelled · expired');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('client_approved_at')->nullable();
            $table->timestamp('freelancer_approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('end_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('freelance_project_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('agreement_id')->nullable()->constrained('freelance_agreements')->nullOnDelete();
            $table->string('action', 40)->comment('agreement.sent · agreement.approved · agreement.declined · agreement.cancelled · agreement.expired');
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedInteger('version')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['project_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_project_approvals');
        Schema::dropIfExists('freelance_agreements');
    }
};
