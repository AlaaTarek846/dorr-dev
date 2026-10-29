<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-403: how a part of the work moves (its history, never deleted) and what was delivered. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_milestones', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('status');
            $table->text('delivery_note')->nullable()->after('version');
            $table->string('revision_reason', 1000)->nullable()->after('delivery_note');
            $table->unsignedSmallInteger('revisions_used')->default(0)->after('revision_reason');
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('approved_at')->nullable();
        });

        Schema::create('freelance_milestone_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_id')->constrained('freelance_milestones')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['milestone_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_milestone_events');
        Schema::table('freelance_milestones', function (Blueprint $table) {
            $table->dropColumn(['version', 'delivery_note', 'revision_reason', 'revisions_used', 'funded_at', 'started_at', 'delivered_at', 'approved_at']);
        });
    }
};
