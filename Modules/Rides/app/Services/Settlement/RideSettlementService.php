<?php

namespace Modules\Rides\Services\Settlement;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\RidePaymentMethod;
use Modules\Rides\Enums\SettlementItemType;
use Modules\Rides\Enums\SettlementStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideSettlementItem;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\FinancialLedgerService;
use Modules\Wallet\Services\WalletService;

/**
 * Money between DORR and the driver after a ride (docs/rides-plan.md §7.7) — Jawad's order, on the
 * wallet's ledger:
 *  - the commission and the tax are DORR's income (`trip_commissions`, `trip_taxes` = «ضريبة التوصيل»);
 *  - a cash ride: the driver kept the cash, so the commission and tax are taken from their wallet now;
 *  - a paid-online ride: the driver's net waits as `pending` until `settlement.pending_days`, then
 *    `rides:release-earnings` puts it in their wallet (the "pending balance", D-209).
 */
class RideSettlementService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly FinancialLedgerService $ledger,
        private readonly RideNotifier $notifier,
    ) {}

    public function completed(Ride $ride): void
    {
        DB::transaction(function () use ($ride) {
            $driver = $ride->driver()->firstOrFail();
            $country = $ride->country()->firstOrFail();
            $currency = $ride->currency()->firstOrFail();
            $commission = (int) $ride->commission_minor;
            $tax = (int) $ride->tax_minor;
            $taxOnDriver = $ride->snapshot('tax.borne_by') !== 'rider';

            if ($commission > 0) {
                $this->ledger->record('trip_commissions', FinancialEntryType::Income, $commission, $currency, $country, $ride,
                    ['key' => 'wallet.notes.ride_commission', 'variables' => ['number' => $ride->number]]);
            }
            if ($tax > 0) {
                $this->ledger->record('trip_taxes', FinancialEntryType::Income, $tax, $currency, $country, $ride,
                    ['key' => 'wallet.notes.ride_tax', 'variables' => ['number' => $ride->number]]);
            }

            if ($ride->payment_method === RidePaymentMethod::Cash) {
                $this->post($ride, $driver, $country, SettlementItemType::Commission, -$commission, WalletTransactionType::Commission, 'ride_commission');
                if ($taxOnDriver) {
                    $this->post($ride, $driver, $country, SettlementItemType::Tax, -$tax, WalletTransactionType::Tax, 'ride_tax');
                }

                return;
            }

            if ((int) $ride->driver_net_minor > 0) {
                RideSettlementItem::query()->create([
                    'ride_id' => $ride->id,
                    'driver_id' => $driver->id,
                    'type' => SettlementItemType::TripEarning,
                    'amount_minor' => (int) $ride->driver_net_minor,
                    'currency_id' => $currency->id,
                    'status' => SettlementStatus::Pending,
                    'release_at' => now()->addDays((int) $ride->policy('settlement.pending_days', 2)),
                ]);
            }
        });
    }

    /**
     * The driver confirms the cash: an old debt collected with the ride goes back to the rider's
     * wallet (and is taken from the driver, who holds it), and any change the driver chose to give
     * as credit moves from the driver's wallet to the rider's (Jawad's "charge client wallet").
     */
    public function cashCollected(Ride $ride, int $receivedMinor): int
    {
        $total = (int) $ride->final_total_minor;
        if ($receivedMinor < $total) {
            throw new RidesException('cash_below_total', 422, [], ['total_minor' => $total]);
        }

        return DB::transaction(function () use ($ride, $receivedMinor, $total) {
            $driver = $ride->driver()->firstOrFail();
            $rider = $ride->rider()->firstOrFail();
            $country = $ride->country()->firstOrFail();
            $driverWallet = $this->wallets->firstOrCreateWallet($driver, $country);
            $riderWallet = $this->wallets->firstOrCreateWallet($rider, $country);
            $meta = fn (string $note, string $suffix) => [
                'reference_type' => 'ride',
                'reference_id' => $ride->id,
                'notes' => ['key' => 'wallet.notes.'.$note, 'variables' => ['number' => $ride->number]],
                'idempotency_key' => "ride:{$ride->uuid}:{$suffix}",
            ];

            $debt = (int) $ride->previous_balance_minor;
            if ($debt > 0) {
                $this->post($ride, $driver, $country, SettlementItemType::DebtCollected, -$debt, WalletTransactionType::DebtSettlement, 'ride_debt_collected');
                $this->wallets->credit($riderWallet, $debt, WalletBucket::Withdrawable, WalletTransactionType::DebtSettlement, $meta('ride_debt_collected', 'debt:rider'));
            }

            $change = $receivedMinor - $total;
            if ($change > 0) {
                $limit = (int) $ride->policy('driver.min_balance_minor', -5000);
                if ($driverWallet->fresh()->withdrawable_minor - $change < $limit) {
                    throw new RidesException('change_too_large', 422, [], ['max_change_minor' => max(0, $driverWallet->fresh()->withdrawable_minor - $limit)]);
                }
                $operation = (string) \Illuminate\Support\Str::uuid();
                $this->wallets->debit($driverWallet, $change, WalletBucket::Withdrawable, WalletTransactionType::TransferOut, $meta('ride_change', 'change:driver') + ['operation_id' => $operation, 'counterparty_wallet_id' => $riderWallet->id]);
                $this->wallets->credit($riderWallet, $change, WalletBucket::SpendOnly, WalletTransactionType::TransferIn, $meta('ride_change', 'change:rider') + ['operation_id' => $operation, 'counterparty_wallet_id' => $driverWallet->id]);
            }

            return $change;
        });
    }

    /** Hourly: pending earnings whose day came go into the drivers' wallets. */
    public function releaseDue(): int
    {
        $due = RideSettlementItem::query()->where('status', SettlementStatus::Pending->value)->where('release_at', '<=', now())->pluck('id');

        foreach ($due as $id) {
            DB::transaction(function () use ($id) {
                $item = RideSettlementItem::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($item->status !== SettlementStatus::Pending) {
                    return;
                }
                $ride = $item->ride()->firstOrFail();
                $wallet = $this->wallets->firstOrCreateWallet($item->driver()->firstOrFail(), $ride->country()->firstOrFail());
                $transaction = $this->wallets->credit($wallet, $item->amount_minor, WalletBucket::Withdrawable, WalletTransactionType::ServiceEarning, [
                    'reference_type' => 'ride',
                    'reference_id' => $ride->id,
                    'notes' => ['key' => 'wallet.notes.ride_earning', 'variables' => ['number' => $ride->number]],
                    'idempotency_key' => "ride:settlement:{$item->uuid}",
                ]);
                $item->update(['status' => SettlementStatus::Posted, 'posted_at' => now(), 'wallet_transaction_id' => $transaction->id]);
                $this->notifier->driver($item->driver, $ride, 'earnings_released', ['amount' => $this->notifier->money($item->amount_minor, $ride)]);
            });
        }

        return $due->count();
    }

    /** A fee the rider paid for a cancellation / no-show after the driver came: it is the driver's (D-IDEA-07). */
    public function cancelCompensation(Ride $ride, int $amountMinor): void
    {
        if ($amountMinor <= 0 || $ride->driver_id === null) {
            return;
        }
        RideSettlementItem::query()->create([
            'ride_id' => $ride->id,
            'driver_id' => $ride->driver_id,
            'type' => SettlementItemType::CancelCompensation,
            'amount_minor' => $amountMinor,
            'currency_id' => $ride->currency_id,
            'status' => SettlementStatus::Pending,
            'release_at' => now()->addDays((int) $ride->policy('settlement.pending_days', 2)),
        ]);
    }

    /** A posted item: the driver's wallet moves now (negative = taken from them, possibly into debt). */
    private function post(Ride $ride, Driver $driver, Country $country, SettlementItemType $type, int $amountMinor, WalletTransactionType $walletType, string $note): void
    {
        if ($amountMinor === 0) {
            return;
        }
        $wallet = $this->wallets->firstOrCreateWallet($driver, $country);
        $meta = [
            'reference_type' => 'ride',
            'reference_id' => $ride->id,
            'notes' => ['key' => 'wallet.notes.'.$note, 'variables' => ['number' => $ride->number]],
            'idempotency_key' => "ride:{$ride->uuid}:{$type->value}",
        ];
        $transaction = $amountMinor > 0
            ? $this->wallets->credit($wallet, $amountMinor, WalletBucket::Withdrawable, $walletType, $meta)
            : $this->wallets->debit($wallet, -$amountMinor, WalletBucket::Withdrawable, $walletType, $meta);

        RideSettlementItem::query()->create([
            'ride_id' => $ride->id,
            'driver_id' => $driver->id,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'currency_id' => $ride->currency_id,
            'status' => SettlementStatus::Posted,
            'posted_at' => now(),
            'wallet_transaction_id' => $transaction->id,
        ]);
    }
}
