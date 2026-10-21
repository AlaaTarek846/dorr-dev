<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * O-67: bus seats, a boat, a driver and a lesson pack are paid on the app's one payment screen (coupon,
 * top-up, back to the same page). A booking is made first, «awaiting payment», holding its seats / time
 * until `payment_expires_at`; the payment screen then confirms it — or the hold lapses and frees them.
 */
return new class extends Migration
{
    private const TABLES = ['bus_bookings', 'marine_bookings', 'driver_hires', 'lesson_enrollments'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dateTime('payment_expires_at')->nullable();
                $table->unsignedBigInteger('discount_minor')->default(0)->comment('the coupon taken off on the payment screen');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['payment_expires_at', 'discount_minor']);
            });
        }
    }
};
