<?php

namespace Modules\Wallet\Support\Payments;

use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Support\OwnerType;
use Modules\Wallet\Models\WithdrawalMethod;

/**
 * Extra rules a module adds to its owners' withdrawals (docs/rides_tasks.md D-216..D-224), without the wallet
 * knowing what a captain is: checked when a payout destination is saved (may refuse, or add to what's stored)
 * and before each withdrawal (may refuse). Registered per owner alias (`driver`, `user`…).
 */
final class WithdrawalGuards
{
    /** @var array<string, array{method: ?callable, withdrawal: ?callable}> */
    private array $guards = [];

    /**
     * @param  callable(Model, array<string, mixed>, ?WithdrawalMethod): array<string, mixed>|null  $onMethod  returns the attributes to save
     * @param  callable(Model, WithdrawalMethod, int): void|null  $onWithdrawal  throws to refuse
     */
    public function register(string $ownerAlias, ?callable $onMethod, ?callable $onWithdrawal): void
    {
        $this->guards[$ownerAlias] = ['method' => $onMethod, 'withdrawal' => $onWithdrawal];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function method(Model $owner, array $attributes, ?WithdrawalMethod $existing = null): array
    {
        $guard = $this->guards[OwnerType::aliasFor($owner)]['method'] ?? null;

        return $guard === null ? $attributes : $guard($owner, $attributes, $existing);
    }

    public function withdrawal(Model $owner, WithdrawalMethod $method, int $amountMinor): void
    {
        $guard = $this->guards[OwnerType::aliasFor($owner)]['withdrawal'] ?? null;
        if ($guard !== null) {
            $guard($owner, $method, $amountMinor);
        }
    }
}
