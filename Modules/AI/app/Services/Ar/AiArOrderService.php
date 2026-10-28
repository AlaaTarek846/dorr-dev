<?php

namespace Modules\AI\Services\Ar;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Modules\AI\Exceptions\AiArException;
use Modules\AI\Exceptions\AiSubscriptionException;
use Modules\AI\Models\Ar\AiArApproval;
use Modules\AI\Models\Ar\AiArExperience;
use Modules\AI\Models\Ar\AiArOrder;
use Modules\AI\Models\Ar\AiArSetting;
use Modules\AI\Services\AiSubscriptionBillingService;
use Modules\Provider\Models\Provider;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\InsufficientBalanceException;
use Modules\Wallet\Services\FinancialLedgerService;
use Modules\Wallet\Services\WalletService;

/**
 * Money for an accepted, priced design.
 *
 *   customer accepts the quote  -> charged from the wallet NOW (spend_only first, like every other charge) and held by the platform
 *   provider delivers           -> the customer has `escrow_release_days` to confirm or dispute
 *   confirm / time runs out     -> provider is paid (price minus commission, withdrawable); commission becomes platform revenue
 *   dispute                     -> money stays held until an admin releases it or refunds the customer
 *   cancel / decline before delivery -> full refund
 *
 * The commission percent is copied onto the order when it is paid, so changing the setting never alters an open order.
 * Every money movement happens inside one transaction with the order row locked, and only from the state that allows it,
 * so a double tap, a retry or the scheduler racing a customer cannot pay out (or refund) twice.
 */
class AiArOrderService
{
    public function __construct(
        protected AiSubscriptionBillingService $billing,
        protected WalletService $wallets,
        protected FinancialLedgerService $ledger,
        protected AiArNotifier $notifier,
    ) {}

    public function acceptAndPay(Authenticatable $owner, AiArExperience $experience): AiArOrder
    {
        $order = DB::transaction(function () use ($owner, $experience) {
            $experience = AiArExperience::query()->lockForUpdate()->findOrFail($experience->id);

            $approval = AiArApproval::query()->where('experience_id', $experience->id)->where('status', AiArApproval::APPROVED)
                ->where('experience_version_id', $experience->current_version_id)->latest('id')->first();

            if ($experience->status !== 'approved' || $approval === null) {
                throw new AiArException('not_approved', 409);
            }

            $amount = (int) ($approval->quote_minor ?? 0);

            if ($amount <= 0) {
                throw new AiArException('payment_not_required', 409);
            }

            $country = ($experience->country_id ? Country::query()->find($experience->country_id) : null) ?? Country::query()->where('is_default', true)->first();
            $wallet = $this->billing->walletFor($owner, $country);

            try {
                $this->billing->assertCurrencyMatches($wallet, (string) $approval->currency);
            } catch (AiSubscriptionException) {
                throw new AiArException('currency_mismatch', 422);
            }

            $decimals = $wallet->currency?->decimal_places ?? 2;

            try {
                $charge = $this->billing->charge($wallet, $amount / (10 ** $decimals), WalletTransactionType::ServicePayment, ['source' => 'ai_ar', 'reference_type' => 'ai_ar_experience', 'reference_id' => $experience->id]);
            } catch (InsufficientBalanceException) {
                throw new AiArException('insufficient_balance', 402);
            }

            $percent = (float) AiArSetting::current()->commission_percent;
            $commission = (int) round($amount * $percent / 100);

            $order = AiArOrder::query()->create([
                'experience_id' => $experience->id, 'approval_id' => $approval->id, 'customer_type' => $experience->owner_type, 'customer_id' => $experience->owner_id,
                'provider_id' => $approval->provider_id, 'country_id' => $country?->id, 'amount_minor' => $amount, 'commission_percent' => $percent,
                'commission_minor' => $commission, 'provider_net_minor' => $amount - $commission, 'currency' => (string) $approval->currency,
                'status' => AiArOrder::PAID, 'charge_operation_id' => $charge['operation_id'], 'paid_at' => now(),
            ]);

            $approval->forceFill(['accepted_at' => now()])->save();
            $experience->forceFill(['status' => 'accepted'])->save();

            return $order;
        });

        $this->notifier->orderPaid($order);

        return $order;
    }

    /** Before delivery either side can walk away: the customer cancels, or the provider declines. Full refund in both cases. */
    public function cancel(AiArOrder $order, string $by): AiArOrder
    {
        $order = $this->transition($order, [AiArOrder::PAID], function (AiArOrder $o) {
            $this->refund($o);
            $o->experience?->forceFill(['status' => 'active'])->save();
        });

        $this->notifier->orderRefunded($order, $by);

        return $order;
    }

    public function deliver(AiArOrder $order, ?string $note): AiArOrder
    {
        $order = $this->transition($order, [AiArOrder::PAID], function (AiArOrder $o) use ($note) {
            $o->forceFill(['status' => AiArOrder::DELIVERED, 'delivery_note' => $note, 'delivered_at' => now(), 'release_at' => now()->addDays((int) AiArSetting::current()->escrow_release_days)])->save();
        });

        $this->notifier->orderDelivered($order);

        return $order;
    }

    public function confirm(AiArOrder $order): AiArOrder
    {
        $order = $this->transition($order, [AiArOrder::DELIVERED], fn (AiArOrder $o) => $this->release($o));
        $this->notifier->orderReleased($order);

        return $order;
    }

    public function dispute(AiArOrder $order, string $reason): AiArOrder
    {
        $order = $this->transition($order, [AiArOrder::DELIVERED], function (AiArOrder $o) use ($reason) {
            if ($o->release_at !== null && $o->release_at->isPast()) {
                throw new AiArException('order_invalid_state', 409);
            }

            $o->forceFill(['status' => AiArOrder::DISPUTED, 'dispute_reason' => $reason])->save();
        });

        $this->notifier->orderDisputed($order);

        return $order;
    }

    /** An admin settles a dispute: pay the provider, or give the money back to the customer. */
    public function resolve(AiArOrder $order, string $resolution, ?string $note): AiArOrder
    {
        $order = $this->transition($order, [AiArOrder::DISPUTED], function (AiArOrder $o) use ($resolution, $note) {
            $o->forceFill(['resolution_note' => $note])->save();
            $resolution === 'release' ? $this->release($o) : $this->refund($o);
        });

        $resolution === 'release' ? $this->notifier->orderReleased($order) : $this->notifier->orderRefunded($order, 'admin');

        return $order;
    }

    /** Scheduler: delivered orders the customer neither confirmed nor disputed in time are paid out. */
    public function releaseDue(): int
    {
        $count = 0;

        AiArOrder::query()->where('status', AiArOrder::DELIVERED)->where('release_at', '<=', now())->pluck('id')->each(function (int $id) use (&$count) {
            try {
                $this->notifier->orderReleased($this->transition(AiArOrder::query()->findOrFail($id), [AiArOrder::DELIVERED], fn (AiArOrder $o) => $this->release($o)));
                $count++;
            } catch (AiArException) {
                // Someone else (customer confirm, dispute) got there first - nothing to do.
            }
        });

        return $count;
    }

    /** Runs $apply on the locked row, only from one of the allowed states. */
    protected function transition(AiArOrder $order, array $from, \Closure $apply): AiArOrder
    {
        return DB::transaction(function () use ($order, $from, $apply) {
            $locked = AiArOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($locked->status, $from, true)) {
                throw new AiArException('order_invalid_state', 409);
            }

            $apply($locked);

            return $locked->refresh();
        });
    }

    protected function release(AiArOrder $order): void
    {
        $country = $order->country_id ? Country::query()->find($order->country_id) : null;
        $provider = Provider::query()->findOrFail($order->provider_id);
        $wallet = $this->wallets->firstOrCreateWallet($provider, $country ?? Country::query()->where('is_default', true)->firstOrFail());

        $tx = $this->wallets->credit($wallet, $order->provider_net_minor, WalletBucket::Withdrawable, WalletTransactionType::ServiceEarning, ['reference_type' => 'ai_ar_order', 'reference_id' => $order->id]);

        $currency = Currency::query()->where('code', $order->currency)->first();

        if ($order->commission_minor > 0 && $currency !== null) {
            $this->ledger->record('service_revenue', FinancialEntryType::Income, $order->commission_minor, $currency, $country, $order, null, $tx, 'AR design commission');
        }

        $order->forceFill(['status' => AiArOrder::RELEASED, 'released_at' => now()])->save();
    }

    protected function refund(AiArOrder $order): void
    {
        $owner = $this->customerOf($order);
        $country = $order->country_id ? Country::query()->find($order->country_id) : null;
        $wallet = $this->billing->walletFor($owner, $country ?? Country::query()->where('is_default', true)->first());

        $this->wallets->credit($wallet, $order->amount_minor, WalletBucket::SpendOnly, WalletTransactionType::Refund, ['reference_type' => 'ai_ar_order', 'reference_id' => $order->id]);

        $order->forceFill(['status' => AiArOrder::REFUNDED, 'refunded_at' => now()])->save();
    }

    public function customerOf(AiArOrder $order): Authenticatable
    {
        $class = Relation::getMorphedModel($order->customer_type) ?? $order->customer_type;

        return $class::query()->findOrFail($order->customer_id);
    }
}
