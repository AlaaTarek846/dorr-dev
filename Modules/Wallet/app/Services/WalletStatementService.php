<?php

namespace Modules\Wallet\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;

/**
 * Read-only queries over the immutable ledger, shared by the customer
 * statement and the admin wallet screen so both filter the same way.
 */
class WalletStatementService
{
    /**
     * Newest first; `id` breaks ties for rows posted in the same second
     * (e.g. a top-up and its fee), keeping the order stable across pages.
     *
     * @param  array<string, mixed>  $filters  from, to, type, bucket, direction
     */
    public function query(Wallet $wallet, array $filters = []): Builder
    {
        return WalletTransaction::query()
            ->with('counterpartyWallet')
            ->where('wallet_id', $wallet->id)
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('type', $v))
            ->when($filters['bucket'] ?? null, fn (Builder $q, $v) => $q->where('bucket', $v))
            ->when($filters['direction'] ?? null, fn (Builder $q, $v) => $q->where('direction', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
