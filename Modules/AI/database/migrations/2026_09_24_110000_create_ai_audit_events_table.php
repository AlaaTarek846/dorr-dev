<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.0 requirements doc S17.6 (audit trail for sensitive events, protected
 * from unauthorized modification) and S16.1 (audit as a first-class
 * tracked entity).
 *
 * This is deliberately separate from ai_safety_events and
 * ai_security_events: those two are narrow, domain-specific logs (a
 * safety rule fired, a security scan found something). ai_audit_events is
 * the general-purpose, append-only ledger of *sensitive actions* across
 * the whole module - a self-service data export, an erase, a duplicate
 * request being replayed instead of re-run, a circuit breaker opening -
 * regardless of which subsystem produced them. No updated_at: a row is
 * written once and never changed, which is the point of an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_audit_events', function (Blueprint $table) {
            $table->id();

            // Who/what the event is about (a user, a provider, or "system"
            // for events with no single owner, e.g. a circuit breaker
            // opening for a provider that affects everyone).
            $table->string('owner_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();

            // Who/what performed the action, when distinct from the
            // owner - e.g. an admin acting on a user's data. Null when the
            // owner acted on their own behalf.
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->string('event_type');
            $table->string('severity')->default('info');

            // What the event is about, e.g. an AiRequest or AiConversation
            // row, without a hard FK - the subject may be deleted (an
            // erase!) while its audit trail must survive.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('trace_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['owner_type', 'owner_id']);
            $table->index('event_type');
            $table->index('trace_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_audit_events');
    }
};
