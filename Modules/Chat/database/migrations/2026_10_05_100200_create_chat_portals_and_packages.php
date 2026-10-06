<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant portals, channel categories and verification, and the packages both are sold in
 * (docs/remaining_chat.md ج.2 / ج.3):
 *
 *  - chat_categories — admin's list (sport, news…) with an icon; a channel and a portal pick one.
 *  - chat_packages — what's for sale: a portal's listing or a channel's verification, for N
 *    weeks / months / years, priced per country (chat_package_prices, in that country's currency).
 *  - chat_portals — a merchant's website card (logo, name + description per language, category);
 *    listed on the portals page while `listed_until` is ahead. It outlives its subscription.
 *  - chat_subscriptions — every paid period (portal or channel), linked to its checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('chat_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_category_id')->constrained('chat_categories')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 100);
            $table->timestamps();
            $table->unique(['chat_category_id', 'locale']);
        });

        Schema::table('chat_groups', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('is_public')->constrained('chat_categories')->nullOnDelete()->comment('channels: category');
            $table->timestamp('verified_until')->nullable()->after('category_id')->comment('channels: paid verification runs until');
            $table->boolean('verified_by_admin')->default(false)->after('verified_until')->comment('channels: verified by hand from the admin');
        });

        Schema::create('chat_packages', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 30)->comment('portal | channel_verification');
            $table->string('period', 10)->comment('week | month | year');
            $table->unsignedSmallInteger('period_count')->default(1);
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['kind', 'status']);
        });

        Schema::create('chat_package_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_package_id')->constrained('chat_packages')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->timestamps();
            $table->unique(['chat_package_id', 'locale']);
        });

        Schema::create('chat_package_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_package_id')->constrained('chat_packages')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
            $table->unique(['chat_package_id', 'country_id']);
        });

        Schema::create('chat_portals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('category_id')->nullable()->constrained('chat_categories')->nullOnDelete();
            $table->string('website_url', 500);
            $table->boolean('status')->default(true)->comment('false = switched off by the admin');
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamp('listed_until')->nullable()->comment('shown on the portals page until');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['owner_type', 'owner_id']);
            $table->index(['status', 'listed_until']);
        });

        Schema::create('chat_portal_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_portal_id')->constrained('chat_portals')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['chat_portal_id', 'locale']);
        });

        // One view per person per portal per day — the count can't be pumped by tapping.
        Schema::create('chat_portal_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_portal_id')->constrained('chat_portals')->cascadeOnDelete();
            $table->string('viewer_type', 20);
            $table->unsignedBigInteger('viewer_id');
            $table->date('viewed_on');
            $table->timestamps();
            $table->unique(['chat_portal_id', 'viewer_type', 'viewer_id', 'viewed_on'], 'chat_portal_views_unique');
        });

        Schema::create('chat_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 30)->comment('portal | channel_verification');
            $table->string('subject_type', 20)->comment('portal | channel');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('chat_package_id')->nullable()->constrained('chat_packages')->nullOnDelete();
            $table->foreignId('checkout_id')->nullable()->constrained('checkouts')->nullOnDelete();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_subscriptions');
        Schema::dropIfExists('chat_portal_views');
        Schema::dropIfExists('chat_portal_translations');
        Schema::dropIfExists('chat_portals');
        Schema::dropIfExists('chat_package_prices');
        Schema::dropIfExists('chat_package_translations');
        Schema::dropIfExists('chat_packages');

        Schema::table('chat_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['verified_until', 'verified_by_admin']);
        });

        Schema::dropIfExists('chat_category_translations');
        Schema::dropIfExists('chat_categories');
    }
};
