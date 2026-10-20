<?php

namespace Modules\Wallet\Services;

use App\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Support\OwnerType;

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

    /** The owner's wallet in this country, if they have one — never picked by an id from the client. */
    public function walletOf(Model $owner, Country $country): ?Wallet
    {
        return Wallet::query()
            ->where('owner_type', OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey())
            ->where('country_id', $country->id)
            ->first();
    }

    /** One row of this wallet by its uuid (null when it isn't this wallet's). */
    public function find(Wallet $wallet, string $uuid): ?WalletTransaction
    {
        return WalletTransaction::query()->with('counterpartyWallet')->where('wallet_id', $wallet->id)->where('uuid', $uuid)->first();
    }

    /**
     * The other rows of the same operation in this wallet (a payment and its commission, a withdrawal and
     * its fee…), in the order they were posted.
     *
     * @return Collection<int, WalletTransaction>
     */
    public function related(WalletTransaction $transaction): Collection
    {
        if ($transaction->operation_id === null) {
            return new Collection;
        }

        return WalletTransaction::query()->where('wallet_id', $transaction->wallet_id)->where('operation_id', $transaction->operation_id)
            ->whereKeyNot($transaction->getKey())->orderBy('created_at')->orderBy('id')->get();
    }
}
