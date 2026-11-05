<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-101: an accepted change is an addendum to the agreement - the version the agreement reached with it and what it did to the price,
 * the days and the parts (before / after) - and a waiting one is reminded once before it runs out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_change_requests', function (Blueprint $table) {
            $table->unsignedInteger('applied_version')->nullable()->after('status');
            $table->json('effect')->nullable()->after('applied_version')->comment('price_minor / duration_days / milestones: [before, after]');
            $table->timestamp('reminded_at')->nullable()->after('respond_by');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_change_requests', function (Blueprint $table) {
            $table->dropColumn(['applied_version', 'effect', 'reminded_at']);
        });
    }
};