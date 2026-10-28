<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-301: a freelancer's offer on a published project — one per freelancer and project. The price is in the project's currency
 * (minor units); the commission and what the freelancer is left with are worked out from the policy when the offer is sent and kept
 * with it (`fees`), so a later policy change never moves an offer already made. Its files are media on the private disk. Every move
 * is a row in `freelance_offer_events` (never changed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_offers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->unsignedBigInteger('price_minor');
            $table->string('currency_code', 10);
            $table->unsignedSmallInteger('duration_days');
            $table->text('details');
            $table->string('status', 20)->default('submitted')->index()->comment('submitted | viewed | shortlisted | rejected | accepted | withdrawn');
            $table->unsignedBigInteger('commission_minor')->default(0);
            $table->unsignedBigInteger('net_minor')->default(0)->comment('what the freelancer is left with');
            $table->json('fees')->nullable()->comment('the commission policy the numbers were worked out with');
            $table->boolean('free')->default(true)->comment('counted in the monthly free quota');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'freelancer_id']);
            $table->index(['freelancer_id', 'created_at']);
        });

        Schema::create('freelance_offer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('freelance_offers')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['offer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_offer_events');
        Schema::dropIfExists('freelance_offers');
    }
};
