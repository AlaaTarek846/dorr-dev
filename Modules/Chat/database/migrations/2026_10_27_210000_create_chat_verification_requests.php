<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account verification, batch 1 (docs/verification_tasks.md AV-010 – AV-019): the request, the documents
 * uploaded with it, and every move between statuses (who, from, to, why) — the trail the account and the
 * staff both read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_verification_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('track', 16)->comment('paid · celebrity');
            $table->string('status', 24)->default('new');
            // The open request of an account, kept here so one account has one open request (REQ-01-03):
            // = user_id while open, null once closed — a unique index does the rest.
            $table->unsignedBigInteger('open_key')->nullable()->unique();

            $table->string('full_name', 191);
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->text('notes')->nullable();

            // Who must act before the deadline: the staff (a review) or the account (a completion).
            $table->timestamp('deadline_at')->nullable();
            $table->string('deadline_owner', 8)->nullable()->comment('admin · user');
            $table->timestamp('deadline_paused_at')->nullable();
            $table->unsignedInteger('deadline_remaining_seconds')->nullable()->comment('what was left when paused');
            $table->timestamp('deadline_warned_at')->nullable();

            $table->string('suspended_from', 24)->nullable()->comment('status before the hold');
            $table->timestamp('identity_checked_at')->nullable();
            $table->unsignedBigInteger('identity_checked_by')->nullable();
            $table->text('completion_reason')->nullable()->comment('what the account was asked to add');
            $table->json('completion_document_type_ids')->nullable();
            $table->text('decision_reason')->nullable()->comment('the reason the account sees');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('activation_timing', 32)->nullable()->comment('null = the track default');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'deadline_at']);
        });

        Schema::create('chat_verification_request_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('chat_verification_requests')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('chat_verification_document_types');
            $table->string('status', 12)->default('pending')->comment('pending · accepted · rejected · replaced');
            $table->string('review_note', 1000)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'document_type_id', 'status'], 'chat_vrd_request_type_status');
        });

        Schema::create('chat_verification_request_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('chat_verification_requests')->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor_type', 8)->comment('user · admin · system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_verification_request_transitions');
        Schema::dropIfExists('chat_verification_request_documents');
        Schema::dropIfExists('chat_verification_requests');
    }
};
