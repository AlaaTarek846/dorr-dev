<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Wasl columns Jawad keeps that were missing (owner's review, 2026-10-14): the last
 * registration time, the vehicle's own rejection reasons and its licence expiry as Wasl sent it
 * (Hijri), Wasl's status words kept as they come, and on the ride the official city names that
 * were sent and Wasl's message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_regulator_status', function (Blueprint $table) {
            // Wasl's own words (VALID, INVALID, PENDING, REJECTED, …), lower-cased — no word is lost.
            $table->string('driver_status', 24)->default('pending')->change();
            $table->string('vehicle_status', 24)->default('pending')->change();
            $table->json('vehicle_rejection_codes')->nullable()->after('rejection_codes')->comment('أسباب رفض المركبة لوحدها');
            $table->string('vehicle_expires_at_hijri', 16)->nullable()->after('vehicle_expires_at')->comment('تاريخ انتهاء رخصة السير زي ما وصل بعته');
            $table->timestamp('last_registered_at')->nullable()->after('registration_attempts')->comment('آخر محاولة تسجيل');
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->string('wasl_origin_city', 64)->nullable()->after('wasl_result_code')->comment('اسم مدينة البداية اللي اتبعت لوصل');
            $table->string('wasl_destination_city', 64)->nullable()->after('wasl_origin_city')->comment('اسم مدينة الوصول اللي اتبعت لوصل');
            $table->string('wasl_result_message', 255)->nullable()->after('wasl_destination_city')->comment('رسالة وصل');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['wasl_origin_city', 'wasl_destination_city', 'wasl_result_message']);
        });

        Schema::table('driver_regulator_status', function (Blueprint $table) {
            $table->dropColumn(['vehicle_rejection_codes', 'vehicle_expires_at_hijri', 'last_registered_at']);
            $table->string('driver_status', 12)->default('pending')->change();
            $table->string('vehicle_status', 12)->default('pending')->change();
        });
    }
};
