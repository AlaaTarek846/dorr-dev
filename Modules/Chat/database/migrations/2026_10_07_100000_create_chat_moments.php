<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * DORR Moments (spec 157–168, docs/remaining_chat.md): occasions as data, never in the app's code.
 *
 *  - chat_moments — the catalog (admin): which countries, the date rule (a fixed Gregorian day,
 *    a Hijri day computed with Umm al-Qura, or dates the admin enters), how long it shows around
 *    the day, its look (colours, emoji, animation, an optional card picture), on / off.
 *  - chat_moment_dates — the admin's corrections: this moment, this country, this year, this day
 *    (a moon sighting that differs) — read before any computed date, no app update needed.
 *  - chat_personal_moments — my own dates (a birthday, an anniversary), never guessed.
 *  - chat_moment_preferences — on / off, which country's occasions, which ones I care about, how
 *    much animation. Nothing is inferred about anyone's religion or nationality.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_moments', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('kind', 20)->comment('religious | national | international | social | cultural | seasonal');
            $table->string('date_rule', 10)->comment('gregorian | hijri | manual');
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedTinyInteger('day')->nullable();
            $table->unsignedSmallInteger('duration_days')->default(1);
            $table->unsignedSmallInteger('show_before_days')->default(3);
            $table->unsignedSmallInteger('show_after_days')->default(0);
            $table->json('countries')->nullable()->comment('country codes; null = everywhere');
            $table->boolean('default_on')->default(true)->comment('false = only for people who pick it');
            $table->string('theme', 30)->default('generic')->comment('the look and animation family');
            $table->string('primary_color', 9)->nullable();
            $table->string('secondary_color', 9)->nullable();
            $table->string('emoji', 16)->nullable();
            $table->string('animation', 20)->nullable()->comment('confetti | lanterns | fireworks | hearts | stars | balloons | flags | snow | petals | sparkles');
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['status', 'kind']);
        });

        Schema::create('chat_moment_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_moment_id')->constrained('chat_moments')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 120);
            $table->string('greeting', 300)->nullable()->comment('a default line for the card');
            $table->timestamps();
            $table->unique(['chat_moment_id', 'locale']);
        });

        Schema::create('chat_moment_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_moment_id')->constrained('chat_moments')->cascadeOnDelete();
            $table->foreignId('country_id')->nullable()->constrained('countries')->cascadeOnDelete()->comment('null = every country');
            $table->unsignedSmallInteger('year')->comment('the Gregorian year it falls in');
            $table->date('date');
            $table->timestamps();
            $table->unique(['chat_moment_id', 'country_id', 'year'], 'chat_moment_dates_unique');
        });

        Schema::create('chat_personal_moments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('kind', 20)->default('birthday')->comment('birthday | anniversary | graduation | wedding | other');
            $table->string('title', 120);
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            $table->unsignedSmallInteger('year')->nullable()->comment('to show "turns 30"');
            $table->string('contact_type', 32)->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedSmallInteger('remind_days_before')->default(1);
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('chat_moment_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->boolean('enabled')->default(true);
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete()->comment('whose occasions; null = my sign-in country');
            $table->string('effects', 10)->default('full')->comment('full | light | off');
            $table->json('picked')->nullable()->comment('moment ids I added (not on by default)');
            $table->json('muted')->nullable()->comment('moment ids I turned off');
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id']);
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->boolean('moments_enabled')->default(true)->after('public_stories_free');
        });
        Cache::forget('chat.settings');

        // The starting catalog, so a fresh deploy has occasions without running seeders.
        (new \Modules\Chat\Database\Seeders\ChatMomentsSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('chat_settings', fn (Blueprint $table) => $table->dropColumn('moments_enabled'));
        Schema::dropIfExists('chat_moment_preferences');
        Schema::dropIfExists('chat_personal_moments');
        Schema::dropIfExists('chat_moment_dates');
        Schema::dropIfExists('chat_moment_translations');
        Schema::dropIfExists('chat_moments');
        Cache::forget('chat.settings');
    }
};
