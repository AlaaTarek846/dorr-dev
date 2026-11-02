<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-L07: paid consultations. A freelancer offers sessions of a few lengths at a price each, in weekly hours of their own; a client books
 * a free time, the price and fee are held in their wallet at once, the session runs in a room of its own, and the money is paid out
 * after it (or given back on a freelancer's cancellation, an early cancellation or a complaint decided for the client).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_consultation_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->unique()->constrained('freelancers')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('title', 120)->nullable();
            $table->text('about')->nullable();
            // [{minutes, price_minor}] — up to four lengths, each at its own price.
            $table->json('options')->nullable();
            // [{day 0-6, from H:i, to H:i}] in `timezone`, several ranges a day allowed.
            $table->json('weekly')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestamps();
        });

        Schema::create('freelance_consultations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries');
            $table->string('request_id', 64)->nullable();
            $table->unsignedSmallInteger('minutes');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('topic');
            $table->string('title', 120)->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('client_fee_minor')->default(0);
            $table->unsignedBigInteger('commission_minor')->default(0);
            $table->unsignedBigInteger('net_minor');
            $table->string('currency_code', 3);
            // The rules as they were when it was booked: free cancel hours, complaint hours, earnings hold days.
            $table->json('policy')->nullable();
            // booked · completed · cancelled · disputed · refunded
            $table->string('status', 12)->default('booked');
            $table->string('cancelled_by', 12)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->boolean('late_cancel')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->text('complaint')->nullable();
            $table->timestamp('complained_at')->nullable();
            // pay (to the freelancer) · refund (to the client)
            $table->string('resolution', 10)->nullable();
            $table->string('resolution_note', 1000)->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('ended_told_at')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('calendar_item_id')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'request_id']);
            $table->index(['freelancer_id', 'starts_at']);
            $table->index(['client_id', 'status']);
            $table->index(['status', 'ends_at']);
        });

        // A consultation's earning belongs to no project or part.
        Schema::table('freelance_earnings', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->change();
            $table->unsignedBigInteger('milestone_id')->nullable()->change();
            $table->foreignId('consultation_id')->nullable()->after('milestone_id')->constrained('freelance_consultations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('freelance_earnings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consultation_id');
        });
        Schema::dropIfExists('freelance_consultations');
        Schema::dropIfExists('freelance_consultation_offers');
    }
};
