<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-country price overrides for an ai_plans row (docs: "اسعار الباقات
     * على حسب البلد وبرده العمله"). A missing row for a given
     * (plan, country) pair is NOT an error - AiPlan::resolvedPriceFor()
     * falls back to the plan's own base price/currency columns, so this
     * table only ever needs to hold the countries an admin has actually
     * customized, never all of them.
     *
     * currency_id is snapshotted from the country's currency_id at the
     * moment the admin sets the price (mirrors Modules/Wallet's own
     * wallets.currency_id snapshot convention) rather than only derived
     * through a join - so a price row keeps its original currency even if
     * a country's currency assignment changes later, and the billing
     * comparison in AiSubscriptionBillingService::assertCurrencyMatches()
     * stays a simple string compare.
     */
    public function up(): void
    {
        Schema::create('ai_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('ai_plans')->cascadeOnDelete()->comment('الباقة');
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete()->comment('الدولة');
            $table->foreignId('currency_id')->constrained('currencies')->comment('عملة الدولة وقت تحديد السعر');
            $table->decimal('price', 10, 2)->comment('سعر الباقة فى الدولة دي');
            $table->decimal('original_price', 10, 2)->nullable()->comment('السعر قبل الخصم (اختياري) لعرض نسبة الخصم');
            $table->timestamps();

            $table->unique(['plan_id', 'country_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_plan_prices');
    }
};
