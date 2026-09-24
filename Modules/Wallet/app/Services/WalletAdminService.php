<?php

namespace Modules\Wallet\Services;

use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletDirection;
use Modules\Wallet\Http\Resources\WalletResource;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Support\OwnerType;

/**
 * The admin's view of wallets: find one, read its statement, and correct it
 * by hand. A correction is never an edit — it's a new, attributed ledger row
 * (bucket + written reason mandatory) plus the matching system income/expense
 * entry, so the platform's own books stay in step with the wallet.
 */
class WalletAdminService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly FinancialLedgerService $ledger,
        private readonly WalletNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  owner_type, owner_id, country_id, search
     */
    public function list(array $filters): JsonResponse
    {
        $query = Wallet::query()
            ->with(['country:id,code', 'currency:id,code'])
            ->when($filters['owner_type'] ?? null, fn (Builder $q, $v) => $q->where('owner_type', $v))
            ->when($filters['owner_id'] ?? null, fn (Builder $q, $v) => $q->where('owner_id', $v))
            ->when($filters['country_id'] ?? null, fn (Builder $q, $v) => $q->where('country_id', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, string $v) => $this->searchOwners($q, $v))
            ->latest('id');

        return ApiResponse::paginated($query, WalletResource::class, __('api.retrieved'));
    }

    public function show(Wallet $wallet): JsonResponse
    {
        return ApiResponse::success(new WalletResource($wallet->load(['country:id,code', 'currency:id,code'])), __('api.retrieved'));
    }

    /**
     * Ledger row + system entry commit together or not at all.
     */
    public function adjust(
        Wallet $wallet,
        WalletDirection $direction,
        WalletBucket $bucket,
        int $amountMinor,
        string $reason,
        int $adminId,
    ): WalletTransaction {
        return DB::transaction(function () use ($wallet, $direction, $bucket, $amountMinor, $reason, $adminId) {
            $transaction = $this->wallets->manualAdjustment(
                $wallet, $amountMinor, $bucket, $direction, $reason, 'admin', $adminId,
            );

            // Money the platform hands out is its cost; money it takes back is income.
            $credit = $direction === WalletDirection::Credit;

            $this->ledger->record(
                $credit ? 'manual_adjustment_expense' : 'manual_adjustment_income',
                $credit ? FinancialEntryType::Expense : FinancialEntryType::Income,
                $amountMinor,
                $wallet->currency,
                $wallet->country,
                notes: ['key' => 'wallet.notes.manual_adjustment', 'variables' => ['reason' => $reason]],
                walletTransaction: $transaction,
                createdBy: ['type' => 'admin', 'id' => $adminId],
            );

            $this->notifier->adjusted($wallet, $direction, $amountMinor, $reason);

            return $transaction;
        });
    }

    /**
     * Owners are polymorphic (user/provider), so search runs against each
     * owner table and matches wallets by (type, id).
     */
    private function searchOwners(Builder $query, string $term): void
    {
        $like = "%{$term}%";

        $digits = preg_replace('/\D/', '', $term) ?? '';

        $query->where(function (Builder $outer) use ($like, $digits) {
            // A pasted/typed wallet number finds the wallet directly.
            if ($digits !== '') {
                $outer->orWhere('wallet_number', 'like', "%{$digits}%");
            }

            foreach (['user', 'provider'] as $alias) {
                $ids = OwnerType::modelClassFor($alias)::query()
                    ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like))
                    ->select('id');

                $outer->orWhere(fn (Builder $w) => $w->where('owner_type', $alias)->whereIn('owner_id', $ids));
            }
        });
    }
}
