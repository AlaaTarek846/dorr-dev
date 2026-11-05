<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-13, B-15 of docs/moving_tasks.md: a change the other side must approve before it counts (a price difference, the
 * waiting fee, a new time — REQ-036 → REQ-040, REQ-046), the customer's rating of the establishment and the driver,
 * and the tickets about a move that go to the establishment first (REQ-062 → REQ-064, REQ-103).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moving_change_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('request_id')->constrained('moving_requests')->cascadeOnDelete();
            $table->string('kind', 12)->comment('change (load, trips, workers) / waiting / reschedule');
            $table->string('proposed_by', 12)->comment('user / provider');
            $table->unsignedBigInteger('proposer_id');
            $table->bigInteger('price_delta_minor')->default(0)->comment('+ more to pay, - less');
            $table->unsignedSmallInteger('workers_count')->nullable();
            $table->unsignedSmallInteger('trips_count')->nullable();
            $table->dateTime('new_scheduled_at')->nullable();
            $table->string('reason', 500);
            $table->json('before')->nullable()->comment('the agreed values before (REQ-036: compare before / after)');
            $table->json('after')->nullable();
            $table->string('status', 12)->default('pending')->comment('pending / awaiting_payment / approved / rejected / withdrawn');
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->index(['request_id', 'status']);
        });

        Schema::create('moving_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->unique()->constrained('moving_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained('provider_establishments')->cascadeOnDelete();
            $table->foreignId('driver_membership_id')->nullable()->constrained('provider_memberships')->nullOnDelete();
            $table->unsignedTinyInteger('establishment_stars');
            $table->unsignedTinyInteger('driver_stars')->nullable();
            $table->string('comment', 1000)->nullable();
            $table->timestamps();
            $table->index(['establishment_id', 'establishment_stars']);
        });

        Schema::create('moving_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('request_id')->constrained('moving_requests')->cascadeOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained('provider_establishments')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20)->comment('suggestion / inquiry / complaint / safety / inventory / delay / financial / note');
            $table->string('subject', 200);
            $table->string('status', 24)->default('new')->comment('new / awaiting_company / company_replied / awaiting_customer / resolved / unresolved / escalated');
            $table->string('escalation_reason', 500)->nullable();
            $table->string('closing_reason', 500)->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->index(['establishment_id', 'status']);
            $table->index(['status', 'escalated_at']);
        });

        Schema::create('moving_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('moving_tickets')->cascadeOnDelete();
            $table->string('author_type', 12)->comment('user / provider / admin');
            $table->unsignedBigInteger('author_id');
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moving_ticket_messages');
        Schema::dropIfExists('moving_tickets');
        Schema::dropIfExists('moving_ratings');
        Schema::dropIfExists('moving_change_orders');
    }
};
