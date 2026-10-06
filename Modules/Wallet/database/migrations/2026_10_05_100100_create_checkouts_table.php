<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One payment screen for every paid thing in the app (a merchant portal's listing, a channel's
 * verification, later any service): the purpose prices it on the server, the customer pays from
 * the wallet or through a gateway, and the purpose delivers it once paid (docs/remaining_chat.md ج.0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 40);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('country_id')->constrained('countries');
            $table->foreignId('currency_id')->constrained('currencies');
            $table->string('purpose', 60)->comment('Registered CheckoutPurpose key, e.g. chat_portal_subscription');
            $table->json('reference')->nullable()->comment('What is being bought, as the purpose understands it');
            $table->string('title', 191);
            $table->string('subtitle', 191)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 20)->default('pending')->comment('pending | paid | failed | expired');
            $table->string('paid_via', 20)->nullable()->comment('wallet | gateway');
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->uuid('wallet_operation_id')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'status']);
        });

        // The ledger line every paid checkout writes — seeded here too, so a deploy that doesn't
        // re-run the seeders still has it.
        if (Schema::hasTable('financial_categories') && ! DB::table('financial_categories')->where('slug', 'service_revenue')->exists()) {
            $id = DB::table('financial_categories')->insertGetId([
                'slug' => 'service_revenue', 'type' => 'income', 'is_system' => true, 'status' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if (Schema::hasTable('financial_category_translations')) {
                foreach (['en' => 'Services & Subscriptions', 'ar' => 'الخدمات والاشتراكات'] as $locale => $name) {
                    DB::table('financial_category_translations')->insert([
                        'financial_category_id' => $id, 'locale' => $locale, 'name' => $name,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};
