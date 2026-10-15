<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rides settings set from the admin (one row): the Google Maps server key (stored encrypted, never
 * shown back in full) and the free estimate used when Google can't answer — Jawad's approach:
 * straight-line distance and an average speed, plus a road factor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_settings', function (Blueprint $table) {
            $table->id();
            $table->text('google_maps_key')->nullable()->comment('مفتاح Google للسيرفر — مشفّر');
            $table->unsignedSmallInteger('fallback_speed_kmh')->default(60)->comment('السرعة المتوسطة للحساب المجاني (Jawad: 60)');
            $table->unsignedInteger('fallback_road_factor_bp')->default(13000)->comment('معامل الطريق على الخط المستقيم: 13000 = ×1.3');
            $table->unsignedInteger('geocode_cache_days')->default(30)->comment('مدة حفظ اسم المدينة لكل نقطة');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_settings');
    }
};
