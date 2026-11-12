<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The map the iPhone app draws (O-72): `follow` — the provider chosen above (Google or Mapbox), like
 * Android — or `apple`, Apple Maps (MapKit, built into iOS, no key). Routes, distances and city names
 * stay with `map_provider` on the server either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_settings', function (Blueprint $table) {
            $table->string('ios_map_provider', 16)->default('follow')->after('mapbox_style')->comment('follow / apple');
        });
    }

    public function down(): void
    {
        Schema::table('ride_settings', function (Blueprint $table) {
            $table->dropColumn('ios_map_provider');
        });
    }
};
