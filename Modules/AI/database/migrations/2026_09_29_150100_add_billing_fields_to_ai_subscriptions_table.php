<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI subscription billing (professional pass, 2026-09-29) - see
     * AiSubscriptionPurchaseService for how each field is used:
     *
     * - auto_renew: the owner's own toggle (default true, matches the
     *   product decision: auto-renewal on by default, user can turn it
     *   off - the subscription then simply expires at ends_at instead of
     *   being charged again).
     * - grace_ends_at: set the moment a renewal charge fails (insufficient
     *   wallet balance) - null means "not currently in a grace window".
     *   The owner keeps full access while grace_ends_at is in the future
     *   (status stays 'active'); the scheduled job flips status to
     *   'suspended' only once grace_ends_at has actually passed with no
     *   successful retry.
     * - current_plan_price / current_plan_duration_days: a snapshot of
     *   the plan's price/duration *at the moment this billing period
     *   started* - ai_plans.price can change later (admin edits it), but
     *   what this specific cycle already charged/promised must not
     *   silently drift; changePlan()'s proration math reads these, not
     *   the live ai_plans row.
     */
    public function up(): void
    {
        Schema::table('ai_subscriptions', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(true)->after('status')
                ->comment('هل يتجدد الاشتراك تلقائياً بخصم من المحفظة عند انتهاء ends_at؟ اليوزر يقدر يوقفها');
            $table->timestamp('grace_ends_at')->nullable()->after('ends_at')
                ->comment('لو التجديد فشل لعدم كفاية الرصيد: نهاية مهلة السماح (3 أيام) - قبلها الاشتراك يفضل شغال، بعدها يتوقف (suspended)');
            $table->decimal('current_plan_price', 10, 2)->nullable()->after('grace_ends_at')
                ->comment('سعر الخطة وقت بداية الدورة الحالية بالظبط - لحساب الـ proration بدقة حتى لو الأدمن غيّر سعر الخطة بعد كده');
            $table->unsignedInteger('current_plan_duration_days')->nullable()->after('current_plan_price')
                ->comment('مدة الخطة بالأيام وقت بداية الدورة الحالية - نفس منطق current_plan_price');
        });
    }

    public function down(): void
    {
        Schema::table('ai_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['auto_renew', 'grace_ends_at', 'current_plan_price', 'current_plan_duration_days']);
        });
    }
};
