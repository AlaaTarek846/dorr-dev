<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The map provider chosen in the admin (O-01, O-02): Google or Mapbox, one of them — the free
 * OpenStreetMap fallback is gone. The Mapbox token is kept encrypted; the apps get it with the map
 * settings to draw the map themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_settings', function (Blueprint $table) {
            $table->string('map_provider', 16)->default('google')->after('google_maps_key')->comment('google / mapbox');
            $table->text('mapbox_token')->nullable()->after('map_provider')->comment('encrypted — a public (pk.) token: the server and the apps use it');
            $table->string('mapbox_style', 80)->default('mapbox/streets-v12')->after('mapbox_token');
        });
    }

    public function down(): void
    {
        Schema::table('ride_settings', function (Blueprint $table) {
            $table->dropColumn(['map_provider', 'mapbox_token', 'mapbox_style']);
        });
    }
};
