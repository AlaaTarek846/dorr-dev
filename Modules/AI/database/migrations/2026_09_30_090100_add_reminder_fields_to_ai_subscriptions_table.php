<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subscription system improvement pass (2026-09-30): lets the renewal
     * scheduler send a "your subscription renews soon" / "your grace period
     * is about to end" notification exactly once per occurrence, instead of
     * re-matching a fragile time window on every hourly run. Both columns
     * are cleared automatically whenever the underlying cycle actually
     * moves on (a successful renewal, a new grace window) - see
     * AiSubscriptionPurchaseService.
     */
    public function up(): void
    {
        Schema::table('ai_subscriptions', function (Blueprint $table) {
            $table->timestamp('renewal_reminder_sent_at')->nullable()->after('grace_ends_at')
                ->comment('آخر مرة اتبعت تنبيه "التجديد قريب" لهذه الدورة - بيتصفّر عند كل تجديد فعلي');
            $table->timestamp('grace_reminder_sent_at')->nullable()->after('renewal_reminder_sent_at')
                ->comment('آخر مرة اتبعت تنبيه "مهلة السماح على وشك الانتهاء" لهذه المهلة بالذات');
        });
    }

    public function down(): void
    {
        Schema::table('ai_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['renewal_reminder_sent_at', 'grace_reminder_sent_at']);
        });
    }
};
