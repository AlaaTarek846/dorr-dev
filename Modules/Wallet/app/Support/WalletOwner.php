<?php

namespace Modules\Wallet\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Whose wallet a request is about. Usually the signed-in account; a module may say otherwise for its own accounts
 * (a provider working in an establishment sees and withdraws the establishment's money, docs/moving_tasks.md A-10),
 * without the wallet knowing what an establishment is. The PIN, its recovery and device trust stay the person's.
 */
final class WalletOwner
{
    public const ATTRIBUTE = 'wallet_owner';

    /** @var array<string, Closure(Request): ?Model> */
    private array $resolvers = [];

    /** @param Closure(Request): ?Model $resolver returns the wallet's owner, or null for the signed-in account */
    public function register(string $accountAlias, Closure $resolver): void
    {
        $this->resolvers[$accountAlias] = $resolver;
    }

    public function resolve(Request $request): ?Model
    {
        $user = $request->user();
        if (! $user instanceof Model) {
            return null;
        }
        $resolver = $this->resolvers[OwnerType::aliasFor($user)] ?? null;

        return $resolver === null ? null : $resolver($request);
    }

    public static function of(Request $request): Model
    {
        return $request->attributes->get(self::ATTRIBUTE) ?? $request->user();
    }
}
