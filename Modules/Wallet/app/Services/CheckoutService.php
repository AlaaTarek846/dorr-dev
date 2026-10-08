<?php

namespace Modules\Wallet\Services;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Wallet\Enums\CheckoutStatus;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\PaymentMethodType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\CheckoutException;
use Modules\Wallet\Http\Resources\PaymentMethodResource;
use Modules\Wallet\Http\Resources\PaymentTransactionResource;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Support\OwnerType;
use Modules\Wallet\Support\Payments\CheckoutPurposes;
use Throwable;

/**
 * The one payment screen (docs/remaining_chat.md ج.0).
 *
 *  1. create(): the purpose prices the thing on the server — the app never sends a price.
 *  2. Paid either
 *     - from the wallet (payWithWallet, behind the PIN): spend_only first, then withdrawable
 *       (wallet-plan §10), one operation; or
 *     - through a gateway (payWithGateway): a top-up of the price — grossed up so a top-up fee
 *       still leaves enough — is started for this checkout, and the moment the gateway confirms
 *       it (PaymentCompletionService → settleFromTopup), the same money pays the checkout, even if
 *       the app was closed meanwhile. Should that ever fail, the money simply stays in the wallet.
 *  3. The purpose delivers it (fulfil) in the same transaction as the payment, exactly once.
 */
class CheckoutService
{
    /** A checkout not paid within this long can't be paid any more (a gateway payment already started still settles). */
    public const EXPIRY_MINUTES = 60;

    public function __construct(
        private readonly CheckoutPurposes $purposes,
        private readonly WalletService $wallets,
        private readonly FinancialLedgerService $ledger,
        private readonly PaymentTopupService $topups,
    ) {}

    /**
     * @param  array<string, mixed>  $reference
     */
    public function create(Model $owner, Country $country, string $purpose, array $reference): Checkout
    {
        $line = $this->purposes->for($purpose)->line($owner, $country, $reference);

        return Checkout::query()->create([
            'uuid' => (string) Str::uuid(),
            'owner_type' => OwnerType::aliasFor($owner),
            'owner_id' => $owner->getKey(),
            'country_id' => $country->id,
            'currency_id' => $country->currency_id,
            'purpose' => $purpose,
            'reference' => $line->reference,
            'title' => mb_substr($line->title, 0, 191),
            'subtitle' => $line->subtitle !== null ? mb_substr($line->subtitle, 0, 191) : null,
            'amount_minor' => $line->amountMinor,
            'status' => CheckoutStatus::Pending,
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
        ]);
    }

    public function findOwn(Model $owner, string $uuid): Checkout
    {
        return Checkout::query()
            ->where('uuid', $uuid)
            ->where('owner_type', OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey())
            ->first() ?? throw CheckoutException::notFound();
    }

    /**
     * @throws CheckoutException
     */
    public function payWithWallet(Model $owner, Checkout $checkout): Checkout
    {
        return DB::transaction(function () use ($checkout) {
            $locked = $this->lockPayable($checkout);

            return $this->settle($locked, 'wallet');
        });
    }

    /**
     * Hands the checkout to a gateway: a top-up for it is started (same PIN, same rules, same
     * screens as a normal top-up). The answer carries the top-up, with its redirect URL / OTP step.
     */
    public function payWithGateway(Model $owner, Checkout $checkout, PaymentMethod $method, string $idempotencyKey, string $locale): Checkout
    {
        $this->lockPayable($checkout->fresh());

        $amount = $this->grossFor($owner, $checkout->country, $method, max(1, $checkout->payableMinor()));
        $payment = $this->topups->initiate($owner, $checkout->country, $method, $amount, "checkout:{$checkout->uuid}:{$idempotencyKey}", $locale);

        $checkout->update(['payment_transaction_id' => $payment->id, 'paid_via' => 'gateway']);

        return $checkout->refresh();
    }

    /**
     * Called once a top-up is paid (PaymentCompletionService, inside its transaction): if it was
     * started for a checkout, the checkout is paid from it now. Never throws — a failure leaves
     * the top-up paid (the money is in the wallet) and the checkout failed, with the reason.
     */
    public function settleFromTopup(PaymentTransaction $payment): void
    {
        $checkout = Checkout::query()->where('payment_transaction_id', $payment->id)->lockForUpdate()->first();

        if ($checkout === null || $checkout->status !== CheckoutStatus::Pending) {
            return;
        }

        try {
            DB::transaction(fn () => $this->settle($checkout, 'gateway'));
        } catch (Throwable $e) {
            Log::warning('[Checkout] could not settle '.$checkout->uuid.' from its top-up: '.$e->getMessage());
            $checkout->update([
                'status' => CheckoutStatus::Failed,
                'failure_reason' => $e instanceof CheckoutException ? $e->errorCode : mb_substr($e->getMessage(), 0, 255),
            ]);
        }
    }

    /**
     * What the payment screen shows: the line, the wallet balance against it, and the country's
     * payment methods — each with what it would actually charge (fee included).
     *
     * @return array<string, mixed>
     */
    public function present(Model $owner, Checkout $checkout): array
    {
        $checkout->loadMissing(['country.currency', 'paymentTransaction.paymentMethod']);
        $country = $checkout->country;
        // Read back: a wallet created just now has no balances loaded yet (they're DB defaults).
        $wallet = Wallet::query()->findOrFail($this->wallets->firstOrCreateWallet($owner, $country)->id);
        $available = $this->spendable($wallet);

        $methods = PaymentMethod::query()
            ->with(['translations', 'translation'])
            ->availableForCountry($country)
            ->where('supports_topup', true)
            ->where('type', PaymentMethodType::Online)
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(function (PaymentMethod $method) use ($owner, $country, $checkout) {
                $row = (new PaymentMethodResource($method))->resolve();
                $charge = null;

                if ($method->isConfigured()) {
                    try {
                        $charge = $this->grossFor($owner, $country, $method, max(1, $checkout->payableMinor()));
                    } catch (Throwable) {
                        $charge = null; // outside this method's limits — it can't take this one
                    }
                }

                return [
                    'id' => $row['id'], 'code' => $row['code'], 'gateway' => $row['gateway'], 'name' => $row['name'],
                    'logo_url' => $row['logo_url'], 'coming_soon' => $row['coming_soon'],
                    'charge_minor' => $charge, 'available' => $charge !== null,
                ];
            })
            ->values();

        $payment = $checkout->paymentTransaction;

        return [
            'id' => $checkout->uuid,
            'purpose' => $checkout->purpose,
            'reference' => $checkout->reference,
            'title' => $checkout->title,
            'subtitle' => $checkout->subtitle,
            'amount_minor' => $checkout->amount_minor,
            // A coupon on it (docs/sports-plan.md §5.2): what's charged is payable_minor.
            'discount_minor' => (int) $checkout->discount_minor,
            'payable_minor' => $checkout->payableMinor(),
            'coupon' => $checkout->coupon_id ? ['code' => $checkout->loadMissing('coupon')->coupon?->code, 'discount_minor' => (int) $checkout->discount_minor] : null,
            'currency_code' => $country->currency?->code,
            'status' => $this->effectiveStatus($checkout)->value,
            'paid_via' => $checkout->paid_via,
            'failure_reason' => $checkout->failure_reason,
            'expires_at' => $checkout->expires_at?->toISOString(),
            'paid_at' => $checkout->paid_at?->toISOString(),
            'wallet' => [
                'available_minor' => $available,
                'enough' => $available >= $checkout->payableMinor(),
                'shortfall_minor' => max(0, $checkout->payableMinor() - $available),
            ],
            'payment_methods' => $methods,
            'payment' => $payment ? (new PaymentTransactionResource($payment))->resolve() : null,
        ];
    }

    // ------------------------------------------------------------------ internals

    private function effectiveStatus(Checkout $checkout): CheckoutStatus
    {
        if ($checkout->status === CheckoutStatus::Pending && $checkout->payment_transaction_id === null && $checkout->expires_at?->isPast()) {
            return CheckoutStatus::Expired;
        }

        return $checkout->status;
    }

    /**
     * @throws CheckoutException
     */
    private function lockPayable(Checkout $checkout): Checkout
    {
        $locked = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);

        if ($locked->status !== CheckoutStatus::Pending) {
            throw CheckoutException::notPending();
        }

        if ($locked->expires_at?->isPast()) {
            if ($locked->payment_transaction_id === null) {
                $locked->update(['status' => CheckoutStatus::Expired]);
            }

            throw CheckoutException::expired();
        }

        // Already handed to a gateway that hasn't answered: paying twice isn't possible.
        if ($locked->payment_transaction_id !== null) {
            $payment = PaymentTransaction::query()->find($locked->payment_transaction_id);

            if ($payment !== null && $payment->status->canBeCompleted()) {
                throw CheckoutException::gatewayPending();
            }
        }

        return $locked;
    }

    /**
     * Takes the money from the wallet, books it, delivers. Inside a transaction, checkout locked.
     */
    private function settle(Checkout $checkout, string $via): Checkout
    {
        $owner = $checkout->owner();
        $wallet = $this->wallets->firstOrCreateWallet($owner, $checkout->country);
        $wallet = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);
        // A coupon is spent here, with the payment (exactly once); the rest is charged.
        $discount = app(CouponService::class)->redeem($checkout);
        $amount = max(0, $checkout->amount_minor - $discount);

        $fromSpendOnly = min(max(0, $wallet->availableMinor(WalletBucket::SpendOnly)), $amount);
        $fromWithdrawable = $amount - $fromSpendOnly;

        if ($fromWithdrawable > max(0, $wallet->availableMinor(WalletBucket::Withdrawable))) {
            throw CheckoutException::insufficientBalance($amount - $this->spendable($wallet));
        }

        $operation = (string) Str::uuid();
        $meta = [
            'operation_id' => $operation,
            'reference_type' => 'checkout',
            'reference_id' => $checkout->id,
            'notes' => ['key' => 'wallet.notes.service_payment', 'variables' => ['title' => $checkout->title]],
        ];

        $last = null;
        foreach ([[WalletBucket::SpendOnly, $fromSpendOnly], [WalletBucket::Withdrawable, $fromWithdrawable]] as [$bucket, $part]) {
            if ($part > 0) {
                $last = $this->wallets->debit($wallet, $part, $bucket, WalletTransactionType::ServicePayment, $meta + [
                    'idempotency_key' => "checkout:{$checkout->uuid}:{$bucket->value}",
                ]);
                $wallet->refresh();
            }
        }

        if ($amount > 0) {
            $this->ledger->record(
                'service_revenue',
                FinancialEntryType::Income,
                $amount,
                $checkout->currency ?? $checkout->loadMissing('currency')->currency,
                $checkout->country,
                reference: $checkout,
                notes: ['key' => 'wallet.notes.service_payment', 'variables' => ['title' => $checkout->title]],
                walletTransaction: $last,
            );
        }

        $this->purposes->for($checkout->purpose)->fulfil($checkout);

        $checkout->update([
            'status' => CheckoutStatus::Paid,
            'paid_via' => $via,
            'wallet_operation_id' => $operation,
            'failure_reason' => null,
            'paid_at' => now(),
        ]);

        return $checkout->refresh();
    }

    private function spendable(Wallet $wallet): int
    {
        return max(0, $wallet->availableMinor(WalletBucket::SpendOnly)) + max(0, $wallet->availableMinor(WalletBucket::Withdrawable));
    }

    /**
     * The top-up that leaves at least `$price` to spend once the gateway's fee (if any) is taken.
     */
    private function grossFor(Model $owner, Country $country, PaymentMethod $method, int $price): int
    {
        $amount = $price;

        for ($i = 0; $i < 6; $i++) {
            $credited = $this->topups->quote($owner, $country, $method, $amount)->totalCreditedMinor();

            if ($credited >= $price) {
                return $amount;
            }

            $amount += $price - $credited;
        }

        return $amount;
    }
}
