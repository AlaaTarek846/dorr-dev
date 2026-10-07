<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ai_plans.currency used to be a free 3-char string (no admin dropdown,
     * no FK, any 3 letters accepted) - a real `currencies` relation exactly
     * like ai_plan_prices.currency_id already has. Backfilled in the same
     * migration (matching the existing string code against currencies.code)
     * so no plan silently loses its currency, then the old string column is
     * dropped - nothing in the app reads it directly any more (AiPlan's
     * `currency` accessor below replaces it for every existing caller).
     */
    public function up(): void
    {
        Schema::table('ai_plans', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('currency')->constrained('currencies')->nullOnDelete()->comment('عملة الباقة');
        });

        DB::table('ai_plans')->select('id', 'currency')->whereNotNull('currency')->get()->each(function ($plan) {
            $currencyId = DB::table('currencies')->where('code', $plan->currency)->value('id');

            if ($currencyId !== null) {
                DB::table('ai_plans')->where('id', $plan->id)->update(['currency_id' => $currencyId]);
            }
        });

        Schema::table('ai_plans', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_plans', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('original_price');
        });

        DB::table('ai_plans')->select('id', 'currency_id')->whereNotNull('currency_id')->get()->each(function ($plan) {
            $code = DB::table('currencies')->where('id', $plan->currency_id)->value('code');

            if ($code !== null) {
                DB::table('ai_plans')->where('id', $plan->id)->update(['currency' => $code]);
            }
        });

        Schema::table('ai_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
        });
    }
};
