<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freelancer plans and subscriptions (FL-L01): the plans and what each gives (offers per month, a lower commission, a trial), their
 * price per country and period, each freelancer's subscription, and the invoice of every charge. A freelancer with no live
 * subscription is on the Free plan — no row needed. Also the ledger line the subscriptions' money is booked under.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->unsignedTinyInteger('rank')->default(0)->comment('0 = Free; a higher rank is a better plan');
            $table->json('name');
            $table->json('description')->nullable();
            // What the plan gives. Null = what the country's policy says (the Free plan keeps all of these null).
            $table->unsignedInteger('offers_monthly')->nullable()->comment('free offers per month; null = the policy quota');
            $table->boolean('offers_unlimited')->default(false);
            $table->unsignedSmallInteger('commission_bp')->nullable()->comment('commission rate in basis points; null = the policy');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('freelance_plan_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('freelance_plans')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('period', 10)->comment('monthly | yearly');
            $table->unsignedBigInteger('price_minor')->comment('minor units of the country currency');
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->unique(['plan_id', 'country_id', 'period']);
        });

        Schema::create('freelance_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('freelance_plans');
            $table->foreignId('country_id')->constrained('countries');
            $table->string('currency_code', 8);
            $table->string('period', 10);
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('status', 16)->comment('trialing | active | past_due | expired');
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('started_at');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            // A change that waits for the end of the period (a downgrade).
            $table->foreignId('pending_plan_id')->nullable()->constrained('freelance_plans')->nullOnDelete();
            $table->string('pending_period', 10)->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['freelancer_id', 'status']);
            $table->index(['status', 'period_end']);
        });

        Schema::create('freelance_subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('subscription_id')->constrained('freelance_subscriptions')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('freelance_plans');
            $table->string('period', 10);
            $table->string('kind', 12)->comment('purchase | upgrade | renewal');
            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('credit_minor')->default(0)->comment('unused time of the old plan taken off');
            $table->unsignedBigInteger('amount_minor')->comment('what was charged');
            $table->string('currency_code', 8);
            $table->string('status', 10)->comment('paid | failed');
            $table->string('failure_code', 40)->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['freelancer_id', 'created_at']);
        });

        if (Schema::hasTable('financial_categories') && ! DB::table('financial_categories')->where('slug', 'freelance_subscriptions')->exists()) {
            $id = DB::table('financial_categories')->insertGetId([
                'slug' => 'freelance_subscriptions', 'type' => 'income', 'is_system' => true, 'status' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (Schema::hasTable('financial_category_translations')) {
                foreach (['en' => 'Freelance subscriptions', 'ar' => 'اشتراكات العمل الحر'] as $locale => $name) {
                    DB::table('financial_category_translations')->insert([
                        'financial_category_id' => $id, 'locale' => $locale, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_subscription_invoices');
        Schema::dropIfExists('freelance_subscriptions');
        Schema::dropIfExists('freelance_plan_prices');
        Schema::dropIfExists('freelance_plans');
    }
};
