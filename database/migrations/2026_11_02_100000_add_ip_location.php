<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a sign-in comes from (docs/watch_earn_tasks.md WE-003, decision D10): the IP's coordinates and city name, and
 * the nearest of the admin's cities (`discover_cities`) the point falls within. Kept per device session (the history)
 * and, the latest one, on the account — Watch & Earn targets cities by that id, never by the IP's city name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_sessions', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('country_code');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('ip_city', 80)->nullable()->after('longitude')->comment('The city name the IP lookup gave (display only)');
            $table->unsignedBigInteger('city_id')->nullable()->after('ip_city')->comment('discover_cities.id the point is in');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('geo_city_id')->nullable()->after('logged_in_country_id')->index()->comment('discover_cities.id of the last sign-in / app open (by IP)');
            $table->decimal('geo_lat', 10, 7)->nullable()->after('geo_city_id');
            $table->decimal('geo_lng', 10, 7)->nullable()->after('geo_lat');
            $table->string('geo_ip', 45)->nullable()->after('geo_lng')->comment('The IP the location was taken from');
            $table->timestamp('geo_at')->nullable()->after('geo_ip');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['geo_city_id', 'geo_lat', 'geo_lng', 'geo_ip', 'geo_at']));
        Schema::table('account_sessions', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude', 'ip_city', 'city_id']));
    }
};
