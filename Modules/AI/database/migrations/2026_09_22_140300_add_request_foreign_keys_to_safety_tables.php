<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * جدول ai_requests (Phase 7) اتبنى دلوقتي، فبنربط عمود request_id
     * البسيط اللي كان متسجل من غير foreign key فى ai_safety_events و
     * ai_safety_scans (Phase 5) بجدول ai_requests فعلياً.
     */
    public function up(): void
    {
        Schema::table('ai_safety_events', function (Blueprint $table) {
            $table->foreign('request_id', 'ai_safety_events_request_id_foreign')
                ->references('id')
                ->on('ai_requests')
                ->nullOnDelete();
        });

        Schema::table('ai_safety_scans', function (Blueprint $table) {
            $table->foreign('request_id', 'ai_safety_scans_request_id_foreign')
                ->references('id')
                ->on('ai_requests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_safety_events', function (Blueprint $table) {
            $table->dropForeign('ai_safety_events_request_id_foreign');
        });

        Schema::table('ai_safety_scans', function (Blueprint $table) {
            $table->dropForeign('ai_safety_scans_request_id_foreign');
        });
    }
};
