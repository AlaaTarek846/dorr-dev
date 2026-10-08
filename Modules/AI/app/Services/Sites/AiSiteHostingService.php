<?php

namespace Modules\AI\Services\Sites;

use App\Models\Country;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Exceptions\AiSubscriptionException;
use Modules\AI\Models\AiSiteHosting;
use Modules\AI\Models\AiSiteHostingPayment;
use Modules\AI\Models\AiSiteHostingPlan;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Services\AiSubscriptionBillingService;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\InsufficientBalanceException;

/**
 * Paid hosting of a finished site on its own name. The preview link stays free
 * and unguessable; hosting pins ONE version (published_version_id) that the
 * public sees, so the customer can keep editing without it going live by accident.
 */
class AiSiteHostingService
{
    /** Names nobody may take: infrastructure words, money/login words that make phishing look real. */
    public const RESERVED = [
        'www', 'admin', 'api', 'app', 'mail', 'email', 'smtp', 'imap', 'pop', 'ftp', 'ns1', 'ns2', 'cdn', 'static', 'assets', 'img',
        'dashboard', 'login', 'signin', 'signup', 'register', 'auth', 'oauth', 'account', 'accounts', 'support', 'help', 'billing',
        'pay', 'payment', 'payments', 'wallet', 'bank', 'secure', 'security', 'verify', 'root', 'test', 'dev', 'staging', 'status',
        'blog', 'sites', 'site', 'preview', 'hosting', 'cpanel', 'webmail', 'dorr', 'dorrapp', 'dorrsites', 'paypal', 'google',
        'facebook', 'apple', 'microsoft', 'amazon', 'whatsapp', 'instagram', 'tiktok', 'vodafone', 'fawry', 'instapay', 'visa',
        'mastercard', 'localhost',
    ];

    public function __construct(
        protected AiSiteStorage $storage,
        protected AiSiteFileGuard $guard,
        protected AiSubscriptionBillingService $billing,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ai.sites.hosting.enabled', true) && (bool) config('ai.sites.enabled', true);
    }

    /** The public address of a hosted site. */
    public function url(AiSiteHosting $hosting): string
    {
        $domain = config('ai.sites.hosting.domain');

        if (filled($domain)) {
            return config('ai.sites.hosting.scheme', 'https').'://'.$hosting->subdomain.'.'.$domain.'/';
        }

        return rtrim(url('/'.config('ai.sites.hosting.path_prefix', 'sites').'/'.$hosting->subdomain), '/').'/';
    }

    /** Reason code why a name cannot be used, or null when it is free. */
    public function nameProblem(string $name): ?string
    {
        $name = strtolower(trim($name));
        $min = (int) config('ai.sites.hosting.name_min', 3);
        $max = (int) config('ai.sites.hosting.name_max', 40);

        if (strlen($name) < $min || strlen($name) > $max || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $name) !== 1 || str_contains($name, '--')) {
            return 'invalid_subdomain';
        }

        $reserved = array_merge(self::RESERVED, array_map('strtolower', (array) config('ai.sites.hosting.reserved', [])));

        if (in_array($name, $reserved, true)) {
            return 'reserved_subdomain';
        }

        if (AiSiteHosting::query()->where('subdomain', $name)->exists()) {
            return 'subdomain_taken';
        }

        return null;
    }

    public function subscribe(Authenticatable $owner, AiSiteProject $project, AiSiteHostingPlan $plan, string $name): AiSiteHosting
    {
        $this->assertEnabled();

        $name = strtolower(trim($name));

        if ($project->isDisabled()) {
            throw new AiSiteException('project_disabled', 403);
        }

        if ($project->current_version_id === null) {
            throw new AiSiteException('no_version_yet', 409);
        }

        if (! $plan->is_active) {
            throw new AiSiteException('plan_unavailable', 404);
        }

        $existing = $project->hosting()->first();

        if ($existing !== null && $existing->isLive()) {
            throw new AiSiteException('already_hosted', 409);
        }

        // A lapsed hosting is replaced by the new subscription (its name is released with it).
        $existing?->delete();

        if ($problem = $this->nameProblem($name)) {
            throw new AiSiteException($problem, $problem === 'subdomain_taken' ? 409 : 422);
        }

        $country = currentCountry();
        $price = $plan->priceFor($country);

        if ($price === null) {
            throw new AiSiteException('plan_unavailable', 404);
        }

        return DB::transaction(function () use ($owner, $project, $plan, $name, $country, $price) {
            $operationId = $this->charge($owner, $country, $price['price'], $price['currency'], $plan->id);
            $now = now();
            $end = $plan->periodEnd($now);

            $hosting = AiSiteHosting::query()->create([
                'project_id' => $project->id,
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getAuthIdentifier(),
                'plan_id' => $plan->id,
                'subdomain' => $name,
                'status' => AiSiteHosting::STATUS_ACTIVE,
                'published_version_id' => $project->current_version_id,
                'amount' => $price['price'],
                'currency' => $price['currency'],
                'period' => $plan->period,
                'country_id' => $country->id,
                'auto_renew' => true,
                'starts_at' => $now,
                'ends_at' => $end,
            ]);

            $this->recordPayment($hosting, 'initial', $operationId, $now, $end);

            return $hosting;
        });
    }

    /** Puts the project's current version in front of the public. */
    public function publish(AiSiteHosting $hosting): AiSiteHosting
    {
        $project = $hosting->project;

        if ($project === null || $project->trashed() || $project->isDisabled()) {
            throw new AiSiteException('project_disabled', 403);
        }

        if (! $hosting->isLive()) {
            throw new AiSiteException('hosting_not_live', 409);
        }

        $version = $project->currentVersion;

        if ($version === null) {
            throw new AiSiteException('no_version_yet', 409);
        }

        // The files were checked when built, but checking again is cheap and this is the public door.
        $this->guard->assertValid($this->storage->versionFiles($version));

        $hosting->forceFill(['published_version_id' => $version->id])->save();

        return $hosting;
    }

    public function setAutoRenew(AiSiteHosting $hosting, bool $autoRenew): AiSiteHosting
    {
        $hosting->forceFill([
            'auto_renew' => $autoRenew,
            'cancelled_at' => $autoRenew ? null : ($hosting->cancelled_at ?? now()),
            'status' => $this->statusAfterAutoRenewChange($hosting, $autoRenew),
        ])->save();

        return $hosting;
    }

    /** Charges one more period now (manual "renew now", or a retry from the scheduler). */
    public function renew(AiSiteHosting $hosting, string $kind = 'renewal'): AiSiteHosting
    {
        $this->assertEnabled();

        if ($hosting->admin_suspended) {
            throw new AiSiteException('hosting_suspended', 403);
        }

        $plan = $hosting->plan;
        $country = $hosting->country_id ? Country::query()->find($hosting->country_id) : null;
        $price = $country !== null ? $plan?->priceFor($country) : null;
        $amount = $price['price'] ?? $hosting->amount;
        $currency = $price['currency'] ?? $hosting->currency;

        if ($plan === null || $country === null) {
            throw new AiSiteException('plan_unavailable', 404);
        }

        return DB::transaction(function () use ($hosting, $plan, $country, $amount, $currency, $kind) {
            $locked = AiSiteHosting::query()->lockForUpdate()->findOrFail($hosting->id);
            $operationId = $this->charge($locked->owner, $country, $amount, $currency, $plan->id);

            // Still running (or in grace) renews seamlessly from the old end; a lapsed one starts today.
            $continuous = in_array($locked->status, [AiSiteHosting::STATUS_ACTIVE, AiSiteHosting::STATUS_GRACE], true) || $locked->ends_at->isFuture();
            $start = $continuous ? $locked->ends_at : now();
            $end = $plan->periodEnd($start);

            // Paying once by hand never switches auto-renew back on for someone who cancelled it.
            $locked->forceFill([
                'status' => $locked->auto_renew ? AiSiteHosting::STATUS_ACTIVE : AiSiteHosting::STATUS_CANCELLED,
                'amount' => $amount,
                'currency' => $currency,
                'starts_at' => $start,
                'ends_at' => $end,
                'grace_ends_at' => null,
                'suspended_at' => null,
            ])->save();

            $this->recordPayment($locked, $kind, $operationId, $start, $end);

            return $locked;
        });
    }

    /**
     * One scheduler pass: renew what is due, start grace for what could not
     * be renewed, suspend what ran out of grace, and drop long-suspended names.
     *
     * @return array{renewed: int, grace: int, suspended: int, deleted: int}
     */
    public function processDue(): array
    {
        $stats = ['renewed' => 0, 'grace' => 0, 'suspended' => 0, 'deleted' => 0];
        $graceDays = (int) config('ai.sites.hosting.grace_days', 3);

        AiSiteHosting::query()->whereIn('status', [AiSiteHosting::STATUS_ACTIVE, AiSiteHosting::STATUS_GRACE])
            ->where('admin_suspended', false)->where('ends_at', '<=', now())->where('auto_renew', true)->get()
            ->each(function (AiSiteHosting $hosting) use (&$stats, $graceDays) {
                try {
                    $this->renew($hosting);
                    $stats['renewed']++;
                } catch (AiSiteException|InsufficientBalanceException|AiSubscriptionException) {
                    if ($hosting->status === AiSiteHosting::STATUS_ACTIVE) {
                        $hosting->forceFill(['status' => AiSiteHosting::STATUS_GRACE, 'grace_ends_at' => $hosting->ends_at->copy()->addDays($graceDays)])->save();
                        $stats['grace']++;
                    }
                }
            });

        // Grace over, or a cancelled hosting whose paid period ended: take it offline.
        AiSiteHosting::query()->where(function ($q) {
            $q->where(fn ($g) => $g->where('status', AiSiteHosting::STATUS_GRACE)->where('grace_ends_at', '<=', now()))
                ->orWhere(fn ($c) => $c->where('status', AiSiteHosting::STATUS_CANCELLED)->where('ends_at', '<=', now()))
                ->orWhere(fn ($a) => $a->where('status', AiSiteHosting::STATUS_ACTIVE)->where('auto_renew', false)->where('ends_at', '<=', now()));
        })->get()->each(function (AiSiteHosting $hosting) use (&$stats) {
            $hosting->forceFill(['status' => AiSiteHosting::STATUS_SUSPENDED, 'suspended_at' => now()])->save();
            $stats['suspended']++;
        });

        $stats['deleted'] = AiSiteHosting::query()->where('status', AiSiteHosting::STATUS_SUSPENDED)
            ->where('suspended_at', '<=', now()->subDays((int) config('ai.sites.hosting.delete_after_days', 30)))
            ->get()->each->delete()->count();

        return $stats;
    }

    protected function statusAfterAutoRenewChange(AiSiteHosting $hosting, bool $autoRenew): string
    {
        if (in_array($hosting->status, [AiSiteHosting::STATUS_SUSPENDED], true)) {
            return $hosting->status;
        }

        if ($autoRenew) {
            return $hosting->status === AiSiteHosting::STATUS_CANCELLED ? AiSiteHosting::STATUS_ACTIVE : $hosting->status;
        }

        return $hosting->status === AiSiteHosting::STATUS_ACTIVE ? AiSiteHosting::STATUS_CANCELLED : $hosting->status;
    }

    protected function charge(Authenticatable $owner, ?Country $country, float $amount, string $currency, int $planId): string
    {
        try {
            $wallet = $this->billing->walletFor($owner, $country);
            $this->billing->assertCurrencyMatches($wallet, $currency);
            $result = $this->billing->charge($wallet, $amount, WalletTransactionType::ServicePayment, [
                'reference_type' => 'ai_site_hosting_plan',
                'reference_id' => $planId,
            ]);
        } catch (InsufficientBalanceException) {
            throw new AiSiteException('insufficient_balance', 402);
        } catch (AiSubscriptionException $e) {
            throw new AiSiteException($e->getMessage() === 'subscription_currency_mismatch' ? 'currency_mismatch' : 'country_not_resolved', 422);
        }

        return (string) ($result['operation_id'] ?? '');
    }

    protected function recordPayment(AiSiteHosting $hosting, string $kind, string $operationId, $start, $end): void
    {
        AiSiteHostingPayment::query()->create([
            'hosting_id' => $hosting->id,
            'kind' => $kind,
            'amount' => $hosting->amount,
            'currency' => $hosting->currency,
            'wallet_operation_id' => $operationId ?: null,
            'period_start' => $start,
            'period_end' => $end,
        ]);
    }

    protected function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new AiSiteException('hosting_disabled', 503);
        }
    }
}
