<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chat feature switched on or off for a country or one city (spec "أمان 2" and the shared
 * requirement 10 — by country and city for now). A city's rule beats its country's; no rule
 * means on. The city is the one nearest the phone (`chat_privacy_settings.city_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_feature_rules', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 32)->comment('calls · voice_rooms · live · stories · ai');
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            // One of Discover's cities (no foreign key: the chat keeps working without Discover).
            $table->unsignedBigInteger('city_id')->nullable()->comment('null = the whole country');
            $table->boolean('enabled')->default(false);
            $table->string('note', 255)->nullable()->comment('Why (a licence, a pilot…) — for the admins');
            $table->timestamps();

            $table->unique(['feature', 'country_id', 'city_id']);
            $table->index(['feature', 'city_id']);
        });

        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable()->after('owner_id')->comment('The city nearest the phone (for city rules); never the coordinates');
            $table->timestamp('city_seen_at')->nullable()->after('city_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_privacy_settings', function (Blueprint $table) {
            $table->dropColumn(['city_id', 'city_seen_at']);
        });
        Schema::dropIfExists('chat_feature_rules');
    }
};
