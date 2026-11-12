<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-L09 (part A): a freelancer's special offer to a client they already worked with — new work at a price of their own, one of their
 * services at a lower price, or one of their consultations at a discount. Accepting starts it at once (the freelancer's own offer is their
 * approval). A client can stop a freelancer's special offers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_special_offers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries');
            $table->string('kind', 20)->comment('custom | service | consultation');
            $table->foreignId('freelance_specialty_id')->nullable()->constrained('freelance_specialties')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('freelance_services')->nullOnDelete();
            $table->string('service_level', 20)->nullable();
            $table->foreignId('consultation_offer_id')->nullable()->constrained('freelance_consultation_offers')->nullOnDelete();
            $table->string('title', 120);
            $table->text('message')->nullable()->comment("the freelancer's personal word to the client");
            $table->text('description')->nullable()->comment('custom: what the work covers');
            $table->unsignedBigInteger('price_minor')->nullable()->comment('custom / service: the price offered');
            $table->unsignedBigInteger('list_price_minor')->nullable()->comment("service: the level's usual price, shown struck through");
            $table->unsignedSmallInteger('discount_bp')->nullable()->comment('consultation: the discount on every length (basis points)');
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->unsignedSmallInteger('revisions')->nullable();
            $table->string('currency_code', 10);
            $table->json('snapshot')->nullable()->comment("what the client sees, as it was when sent (the service level's name and features, the consultation's lengths)");
            $table->string('status', 20)->default('sent')->comment('sent | accepted | declined | withdrawn | expired');
            $table->timestamp('expires_at');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('reminded_at')->nullable()->comment('the client was told it ends soon (once)');
            $table->timestamp('answered_at')->nullable();
            $table->string('decline_reason', 300)->nullable();
            $table->string('request_id', 64)->nullable();
            // What accepting it made.
            $table->foreignId('project_id')->nullable()->constrained('freelance_projects')->nullOnDelete();
            $table->foreignId('service_order_id')->nullable()->constrained('freelance_service_orders')->nullOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained('freelance_consultations')->nullOnDelete();
            $table->timestamps();
            $table->unique(['freelancer_id', 'request_id']);
            $table->index(['freelancer_id', 'status', 'created_at']);
            $table->index(['client_id', 'status', 'expires_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('freelance_special_offer_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['client_id', 'freelancer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_special_offer_mutes');
        Schema::dropIfExists('freelance_special_offers');
    }
};
