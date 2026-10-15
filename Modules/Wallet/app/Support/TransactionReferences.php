<?php

namespace Modules\Wallet\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Models\WalletTransaction;

/**
 * What a ledger row points at, for the "details of this transaction" screen: a ride, an order… The
 * module that owns the thing registers a resolver for its `reference_type` from its service provider's
 * boot() (as with CheckoutPurposes); the wallet never learns about their tables. A resolver gets the
 * reference id and the wallet's owner and answers the few facts the owner may see — or null (not found,
 * or the owner isn't part of it).
 */
class TransactionReferences
{
    /** @var array<string, Closure(int, Model): (array<string, mixed>|null)> */
    private array $resolvers = [];

    /**
     * @param  Closure(int, Model): (array<string, mixed>|null)  $resolver
     */
    public function register(string $type, Closure $resolver): void
    {
        $this->resolvers[$type] = $resolver;
    }

    /**
     * @return array<string, mixed>|null `['type' => …, …the resolver's facts]`
     */
    public function resolve(WalletTransaction $transaction, Model $owner): ?array
    {
        $type = $transaction->reference_type;
        if ($type === null || $transaction->reference_id === null || ! isset($this->resolvers[$type])) {
            return null;
        }
        $facts = ($this->resolvers[$type])((int) $transaction->reference_id, $owner);

        return $facts === null ? null : ['type' => $type] + $facts;
    }
}
