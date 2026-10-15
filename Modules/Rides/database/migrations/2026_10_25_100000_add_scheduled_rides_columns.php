<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled rides (R-12, R-105..108, R-165, R-170, D-260..269): a captain may reserve a booked ride
 * ahead of time; the ride starts at `scheduled_at − scheduled.lead_time_s`; reminders go out once.
 * `search_started_at` is when the search really began (a scheduled ride is booked long before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->foreignId('reserved_driver_id')->nullable()->after('driver_id')->constrained('drivers')->nullOnDelete()->comment('الكابتن اللي حجز الرحلة المجدولة مقدماً');
            $table->timestamp('reserved_at')->nullable()->after('reserved_driver_id');
            $table->timestamp('reminded_at')->nullable()->after('scheduled_at')->comment('اتبعت تذكير الموعد');
            $table->timestamp('search_started_at')->nullable()->after('requested_at');
            $table->index(['reserved_driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex(['reserved_driver_id', 'status']);
            $table->dropConstrainedForeignId('reserved_driver_id');
            $table->dropColumn(['reserved_at', 'reminded_at', 'search_started_at']);
        });
    }
};
