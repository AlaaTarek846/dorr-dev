<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reminders for my own dates (DORR Moments): "Sara's birthday is tomorrow" N days before, and
 * "…is today" on the day — each once per occurrence (the date it was sent for is kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_personal_moments', function (Blueprint $table) {
            $table->date('reminded_before_for')->nullable()->after('remind_days_before');
            $table->date('reminded_day_for')->nullable()->after('reminded_before_for');
        });
    }

    public function down(): void
    {
        Schema::table('chat_personal_moments', function (Blueprint $table) {
            $table->dropColumn(['reminded_before_for', 'reminded_day_for']);
        });
    }
};
