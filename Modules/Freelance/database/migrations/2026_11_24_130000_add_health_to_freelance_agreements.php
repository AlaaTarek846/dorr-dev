<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-106: the state the alerts last saw for an agreement's project (healthy / at_risk / delayed / blocked), so a side is told once when
 * it gets worse - not every hour while it stays so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_agreements', function (Blueprint $table) {
            $table->string('health_state', 16)->default('healthy')->after('report_late_notified_at');
            $table->timestamp('health_alerted_at')->nullable()->after('health_state');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_agreements', function (Blueprint $table) {
            $table->dropColumn(['health_state', 'health_alerted_at']);
        });
    }
};
