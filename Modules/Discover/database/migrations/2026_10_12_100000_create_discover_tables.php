<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR Discover (spec 169–182):
 *  - discover_settings — the admin's switches (on, which countries, organizer submissions, alerts,
 *    when an event room closes, the AI query) (182);
 *  - discover_categories / discover_cities (+ translations) — catalogs the admin manages; nothing
 *    is hard-coded in the apps (rule 30.2);
 *  - discover_organizers — who may submit events, reviewed before they're trusted (180, AT-DISC-02);
 *  - discover_events — the event, its official source and booking link, place, time with its zone,
 *    price, status and review (169, 174–176); discover_event_status_history — every change (175);
 *  - discover_interests — "interested" / saved, with or without updates (177, AT-DISC-01);
 *  - discover_follows — cities and countries I follow, wherever I am (170);
 *  - discover_preferences — my interests and "don't miss it" alerts (171, 172); discover_alert_log —
 *    never the same event twice;
 *  - discover_event_rooms — the chat groups made for an event (179).
 * Every timestamp is nullable: strict MySQL refuses a second NOT NULL timestamp without a default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discover_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->json('enabled_countries')->nullable()->comment('country ids; null = everywhere');
            $table->boolean('submissions_enabled')->default(true);
            $table->boolean('auto_publish_verified')->default(true)->comment('a verified organizer\'s events go live without review');
            $table->boolean('alerts_enabled')->default(true);
            $table->unsignedTinyInteger('max_alerts_per_week')->default(3);
            $table->unsignedSmallInteger('room_close_hours')->default(24);
            $table->boolean('ai_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('discover_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('emoji', 16)->nullable();
            $table->string('color', 9)->nullable();
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('discover_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discover_category_id')->constrained('discover_categories')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 80);
            $table->unique(['discover_category_id', 'locale'], 'discover_cat_tr_locale_unique');
        });

        Schema::create('discover_cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('timezone', 64);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('discover_city_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discover_city_id')->constrained('discover_cities')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 80);
            $table->unique(['discover_city_id', 'locale']);
        });

        Schema::create('discover_organizers', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('name', 120);
            $table->string('about', 1000)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('status', 20)->default('pending')->comment('pending | verified | rejected | suspended');
            $table->string('review_note', 500)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
        });

        Schema::create('discover_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organizer_id')->nullable()->constrained('discover_organizers')->nullOnDelete();
            $table->string('source', 20)->default('organizer')->comment('organizer | admin');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('language', 10)->nullable();
            $table->foreignId('category_id')->constrained('discover_categories');
            $table->foreignId('country_id')->constrained('countries');
            $table->foreignId('city_id')->constrained('discover_cities');
            $table->string('venue', 160)->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('starts_at')->nullable()->comment('UTC');
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 64)->comment('the place\'s zone');
            $table->boolean('is_free')->default(true);
            $table->string('price_text', 80)->nullable();
            $table->string('source_url', 500)->nullable()->comment('the official page');
            $table->string('booking_url', 500)->nullable()->comment('the official booking / registration');
            $table->boolean('family_friendly')->default(false);
            $table->string('status', 20)->default('confirmed')->comment('confirmed | postponed | cancelled | ended | sold_out');
            $table->string('review_status', 20)->default('pending')->comment('pending | approved | rejected');
            $table->string('review_note', 500)->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('dedupe_key', 80)->index();
            $table->unsignedInteger('interested_count')->default(0);
            $table->timestamps();
            $table->index(['country_id', 'city_id', 'starts_at']);
            $table->index(['review_status', 'status', 'starts_at']);
        });

        Schema::create('discover_event_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('discover_events')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('note', 300)->nullable();
            $table->timestamp('old_starts_at')->nullable()->comment('a moved event: when it was');
            $table->string('changed_by', 20)->comment('organizer | admin | system');
            $table->timestamps();
        });

        Schema::create('discover_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('discover_events')->cascadeOnDelete();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->boolean('notify')->default(true)->comment('tell me when it changes');
            $table->timestamps();
            $table->unique(['event_id', 'owner_type', 'owner_id']);
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('discover_follows', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('kind', 10)->comment('city | country');
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'kind', 'target_id']);
        });

        Schema::create('discover_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->json('categories')->nullable()->comment('my interests (category ids)');
            $table->boolean('alerts')->default(true)->comment('"don\'t miss it"');
            $table->unsignedSmallInteger('alert_days')->default(14)->comment('how far ahead');
            $table->boolean('family_only')->default(false);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
        });

        Schema::create('discover_alert_log', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('event_id')->constrained('discover_events')->cascadeOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->unique(['owner_type', 'owner_id', 'event_id']);
        });

        Schema::create('discover_event_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('discover_events')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['discover_event_rooms', 'discover_alert_log', 'discover_preferences', 'discover_follows', 'discover_interests', 'discover_event_status_history',
            'discover_events', 'discover_organizers', 'discover_city_translations', 'discover_cities', 'discover_category_translations', 'discover_categories', 'discover_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
