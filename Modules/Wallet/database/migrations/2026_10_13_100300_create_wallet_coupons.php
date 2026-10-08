<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons (decision 2026-10-06, docs/sports-plan.md §5.2): a discount — a percentage or a fixed
 * amount, in a country's currency — spent on the one payment screen (Checkout). Issued as a prize
 * (DORR Sports contests) or by the admin; assigned to one person or open to anyone with the code.
 *  - wallet_coupons — the code, kind and value, caps, where it can be used, until when, how often;
 *  - wallet_coupon_redemptions — every use, with the checkout and the discount it gave;
 *  - checkouts.coupon_id / discount_minor — the coupon on a payment (what's charged = amount − discount).
 * Also seeds the `prize_cost` ledger category (wallet prizes are a marketing expense).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('owner_type', 32)->nullable()->comment('assigned to one account, or open (null)');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->string('kind', 10)->comment('percent | fixed');
            $table->unsignedBigInteger('value')->comment('percent (1–100) or amount in minor units');
            $table->unsignedBigInteger('max_discount_minor')->nullable();
            $table->unsignedBigInteger('min_amount_minor')->nullable();
            $table->json('purposes')->nullable()->comment('checkout purposes it applies to; null = all');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('usage_limit')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->string('source', 30)->default('admin')->comment('admin | sports_contest');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('status', 10)->default('active')->comment('active | used | expired | revoked');
            $table->timestamps();
            $table->index(['owner_type', 'owner_id', 'status']);
        });

        Schema::create('wallet_coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('wallet_coupons')->cascadeOnDelete();
            $table->foreignId('checkout_id')->constrained('checkouts')->cascadeOnDelete();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('discount_minor');
            $table->timestamps();
            $table->unique(['coupon_id', 'checkout_id']);
        });

        Schema::table('checkouts', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('amount_minor')->constrained('wallet_coupons')->nullOnDelete();
            $table->unsignedBigInteger('discount_minor')->default(0)->after('coupon_id');
        });

        if (Schema::hasTable('financial_categories')) {
            (new \Modules\Wallet\Database\Seeders\FinancialCategorySeeder)->run();
        }
    }

    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('discount_minor');
        });
        Schema::dropIfExists('wallet_coupon_redemptions');
        Schema::dropIfExists('wallet_coupons');
    }
};
