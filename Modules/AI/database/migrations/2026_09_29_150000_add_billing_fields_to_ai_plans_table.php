<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI subscription billing (professional pass, 2026-09-29): ai_plans had
     * price/currency but no billing period at all - AiSubscription::ends_at
     * had nothing to compute itself from, so every subscription row so far
     * was either never-expiring or admin-typed by hand. duration_days is
     * that missing period (30 for a monthly plan, 365 for yearly, etc.).
     */
    public function up(): void
    {
        Schema::table('ai_plans', function (Blueprint $table) {
            $table->unsignedInteger('duration_days')->default(30)->after('cooldown_minutes')
                ->comment('عدد أيام صلاحية الخطة لكل دورة اشتراك (30 = شهري، 365 = سنوي، ...) - أساس حساب ends_at والتجديد والـ proration');
        });
    }

    public function down(): void
    {
        Schema::table('ai_plans', function (Blueprint $table) {
            $table->dropColumn('duration_days');
        });
    }
};
