<?php

namespace Modules\Wallet\Services;

use App\Models\Country;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Models\WalletFeeRule;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Support\Payments\TopupQuote;

/**
 * Picks the one wallet_fee_rules row that applies to a top-up and turns it
 * into a TopupQuote — docs/wallet-plan.md §12 / wallet-structure.md §4.
 *
 * No matching rule ⇒ percent 0 (no fee, no bonus). Rules never stack: the
 * single most specific rule wins.
 */
class FeeService
{
    public function resolveRule(
        Country $country,
        PaymentMethod $paymentMethod,
        string $ownerAlias,
        int $ownerId,
        ?CarbonInterface $at = null,
    ): ?WalletFeeRule {
        $at ??= now();

        $candidates = WalletFeeRule::query()
            ->where('operation', WalletFeeRule::OPERATION_TOPUP)
            ->where('status', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at))
            ->where(fn (Builder $q) => $q->whereNull('owner_type')->orWhere('owner_type', $ownerAlias))
            ->where(fn (Builder $q) => $q->whereNull('country_id')->orWhere('country_id', $country->id))
            ->where(fn (Builder $q) => $q->whereNull('payment_method_id')->orWhere('payment_method_id', $paymentMethod->id))
            ->get()
            ->filter(fn (WalletFeeRule $rule) => $this->isUsable($rule, $ownerAlias, $ownerId));

        return $candidates
            ->sort(function (WalletFeeRule $a, WalletFeeRule $b) {
                // Most specific first (country + method > country > method > general),
                // then priority, then the oldest rule.
                return [$this->specificity($b), $b->priority, $a->id] <=> [$this->specificity($a), $a->priority, $b->id];
            })
            ->first();
    }

    /**
     * Integer-only maths (basis points), rounding half up — no float drift on
     * money. `percent` has 4 decimals, so 1% = 10000 bp and a fee is
     * amount × bp ÷ 1,000,000.
     */
    public function quote(int $requestedAmountMinor, ?WalletFeeRule $rule): TopupQuote
    {
        if ($rule === null || (float) $rule->percent === 0.0) {
            return new TopupQuote($requestedAmountMinor, $rule, '0.0000', 0, 0);
        }

        $basisPoints = (int) round(abs((float) $rule->percent) * 10000);
        $magnitude = intdiv($requestedAmountMinor * $basisPoints + 500_000, 1_000_000);

        if ($magnitude > 0 && $rule->min_amount_minor !== null) {
            $magnitude = max($magnitude, $rule->min_amount_minor);
        }

        if ($rule->max_amount_minor !== null) {
            $magnitude = min($magnitude, $rule->max_amount_minor);
        }

        if ($rule->isBonus()) {
            $remaining = $rule->budgetRemainingMinor();

            if ($remaining !== null) {
                $magnitude = min($magnitude, $remaining);
            }

            return new TopupQuote($requestedAmountMinor, $rule, (string) $rule->percent, 0, $magnitude);
        }

        // A fee can never eat more than the whole payment.
        return new TopupQuote($requestedAmountMinor, $rule, (string) $rule->percent, min($magnitude, $requestedAmountMinor), 0);
    }

    private function isUsable(WalletFeeRule $rule, string $ownerAlias, int $ownerId): bool
    {
        if ($rule->isBonus() && $rule->budgetRemainingMinor() === 0) {
            return false;
        }

        if ($rule->max_uses_per_owner !== null && $this->usesByOwner($rule, $ownerAlias, $ownerId) >= $rule->max_uses_per_owner) {
            return false;
        }

        return true;
    }

    /**
     * How many times this owner already got this rule applied — counted from
     * the ledger itself, there's no separate counter table to drift.
     */
    private function usesByOwner(WalletFeeRule $rule, string $ownerAlias, int $ownerId): int
    {
        return WalletTransaction::query()
            ->where('fee_rule_id', $rule->id)
            ->whereIn('type', [WalletTransactionType::TopupFee, WalletTransactionType::TopupBonus])
            ->whereHas('wallet', fn (Builder $q) => $q->where('owner_type', $ownerAlias)->where('owner_id', $ownerId))
            ->count();
    }

    private function specificity(WalletFeeRule $rule): int
    {
        return ($rule->country_id !== null ? 2 : 0) + ($rule->payment_method_id !== null ? 1 : 0);
    }
}
