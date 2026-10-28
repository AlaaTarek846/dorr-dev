<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_ar_settings', function (Blueprint $table) {
            // Platform commission, taken from the PROVIDER's side so the customer pays exactly the quoted price.
            $table->decimal('commission_percent', 5, 2)->default(10);
            // Days the customer has to confirm or dispute after delivery before the money is released automatically.
            $table->unsignedTinyInteger('escrow_release_days')->default(3);
        });

        Schema::create('ai_ar_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experience_id')->constrained('ai_ar_experiences')->cascadeOnDelete();
            $table->foreignId('approval_id')->unique()->constrained('ai_ar_approvals')->cascadeOnDelete();
            $table->string('customer_type', 20);
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('provider_id')->index();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->decimal('commission_percent', 5, 2); // snapshot at payment: later changes never touch an open order
            $table->unsignedBigInteger('commission_minor');
            $table->unsignedBigInteger('provider_net_minor');
            $table->string('currency', 3);
            $table->string('status', 20)->default('paid')->index(); // paid | delivered | disputed | released | refunded
            $table->string('delivery_note', 500)->nullable();
            $table->string('dispute_reason', 500)->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->string('charge_operation_id', 64)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('release_at')->nullable()->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['customer_type', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_ar_orders');
        Schema::table('ai_ar_settings', function (Blueprint $table) {
            $table->dropColumn(['commission_percent', 'escrow_release_days']);
        });
    }
};
