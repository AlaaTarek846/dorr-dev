<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR Circle (R-INN-07, D-INN-07) — a ride offered first to the rider's favourite captains only:
 *  - rides.search_scope: `all` (the usual search) or `circle`;
 *  - rides.circle_driver_ids: the favourites it was booked for (kept after the rider widens the search);
 *  - rides.circle_asked_at: the rider was asked "search all captains?" once the circle had no one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->string('search_scope', 8)->default('all')->comment('all / circle');
            $table->json('circle_driver_ids')->nullable()->comment('الكباتن المفضلين اللي الرحلة اتحجزت ليهم');
            $table->timestamp('circle_asked_at')->nullable()->comment('اتسأل يوسّع البحث لكل الكباتن؟');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['search_scope', 'circle_driver_ids', 'circle_asked_at']);
        });
    }
};
