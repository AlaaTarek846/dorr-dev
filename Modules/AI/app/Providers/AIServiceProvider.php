<?php

namespace Modules\AI\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\AI\Console\Commands\EnforceAiDataRetention;
use Modules\AI\Console\Commands\NormalizeAiModels;
use Modules\AI\Console\Commands\ProcessAiSubscriptionRenewals;
use Modules\AI\Console\Commands\RunAiBenchmark;
use Modules\AI\Console\Commands\SyncAiModels;

class AIServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'AI';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'ai';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        EnforceAiDataRetention::class,
        RunAiBenchmark::class,
        SyncAiModels::class,
        NormalizeAiModels::class,
        ProcessAiSubscriptionRenewals::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        // AI chat is shared between the User and Provider dashboards:
        // ai_conversations.owner is polymorphic (owner_type + owner_id).
        // A morph map keeps the stored owner_type short and decoupled from
        // the real model namespace.
        //
        // Deliberately morphMap() and NOT enforceMorphMap(): the enforced
        // variant makes Laravel throw ClassMorphViolationException for
        // *any* polymorphic relation anywhere in the whole app that still
        // uses a full class name instead of an alias - including ones this
        // module has nothing to do with (e.g. App\Models\PlatformSetting).
        // A plain, non-enforced map only supplies short aliases for the
        // classes we register here, and leaves every other model in the
        // app free to keep using its full class name as before.
        Relation::morphMap([
            'user' => \Modules\User\Models\User::class,
            'provider' => \Modules\Provider\Models\Provider::class,
        ]);

        $this->registerRateLimiters();
    }

    /**
     * v2.0 requirements doc S15.4 (rate limiting for costly operations).
     *
     * Two named limiters, keyed per authenticated owner (falling back to
     * IP only for the rare unauthenticated case) rather than globally, so
     * one heavy user cannot starve another:
     *
     * - ai-chat-general: the read/list/admin-ish endpoints (status, usage,
     *   conversation CRUD) - generous, this is not where the cost is.
     * - ai-chat-send: sending an actual message, which triggers a real
     *   paid call to an AI provider - deliberately tighter. This is
     *   independent of, and in addition to, AiChatUsageGuard's per-plan
     *   minute quota: that guard enforces the *business* limit (how much
     *   usage a plan grants), this limiter enforces *abuse* protection
     *   (how fast requests may arrive) even for a user still well within
     *   their plan quota.
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('ai-chat-general', function (Request $request) {
            return Limit::perMinute(60)->by($this->rateLimitKey($request));
        });

        RateLimiter::for('ai-chat-send', function (Request $request) {
            return Limit::perMinute(15)->by($this->rateLimitKey($request));
        });

        // Admin CRUD/config endpoints are trusted-staff traffic, not the
        // AI-provider cost path, so this is generous headroom against a
        // runaway script/loop rather than a tight business limit.
        RateLimiter::for('ai-admin-general', function (Request $request) {
            return Limit::perMinute(180)->by($this->rateLimitKey($request));
        });
    }

    protected function rateLimitKey(Request $request): string
    {
        $owner = $request->user('user_api') ?? $request->user('provider_api') ?? $request->user('admin_api');

        return $owner ? $owner->getMorphClass().':'.$owner->getAuthIdentifier() : $request->ip();
    }

    /**
     * Define module schedules.
     * 
     * @param $schedule
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        // v2.0 requirements doc 17.3 (data retention): purge AI audit
        // rows older than the configured policy once a day.
        $schedule->command('ai:enforce-retention')->daily();

        // Dynamic Model Registry (section 17): keep every provider's
        // model list in sync with what it actually offers, without ever
        // calling a provider's /models endpoint from a request thread.
        // Interval is configurable (AI_MODEL_SYNC_INTERVAL_HOURS, default
        // 24) rather than a fixed cron guess, per the user's own explicit
        // "لا تضع جدول زمني عشوائي" instruction - a fast-moving catalog
        // can be synced hourly, a stable one once a day, without a code
        // change either way.
        $schedule->command('ai:sync-models')
            ->cron($this->modelSyncCronExpression())
            ->onOneServer();

        // Professional AI subscription billing (2026-09-29): renewals,
        // grace periods and expirations all depend on wall-clock time
        // having actually moved, so this has to run at least daily -
        // hourly instead, so a renewal/suspension is never more than an
        // hour late for an owner whose ends_at/grace_ends_at just passed.
        $schedule->command('ai:process-subscription-renewals')
            ->hourly()
            ->onOneServer();
    }

    /**
     * Turns config('ai.model_sync.interval_hours') into a cron
     * expression. 24 (the default) -> once daily at 03:00 (a quiet hour,
     * not exactly midnight, so it does not collide with every other
     * "run at midnight" job on the box); any other N -> every N hours.
     */
    protected function modelSyncCronExpression(): string
    {
        $hours = max(1, (int) config('ai.model_sync.interval_hours', 24));

        if ($hours >= 24 && $hours % 24 === 0) {
            $days = intdiv($hours, 24);

            return $days === 1 ? '0 3 * * *' : "0 3 */{$days} * *";
        }

        return "0 */{$hours} * * *";
    }
}
