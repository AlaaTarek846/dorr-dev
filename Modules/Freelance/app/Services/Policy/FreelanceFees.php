<?php

namespace Modules\Freelance\Services\Policy;

/**
 * The money of one amount under a policy (FL-008): what the client pays on top (the client fee), what
 * the platform keeps from the freelancer (the commission), and what the freelancer is left with.
 * Everything in the minor unit of the country's currency, whole numbers only.
 *
 *   client pays  = amount + client fee
 *   freelancer   = amount − commission
 *
 * A fee is a percent of the amount (basis points, rounded half up to the minor unit) or a fixed sum,
 * then held between its min and max when they are set. The commission never passes the amount itself.
 * The same function serves the cost calculator (FL-510) and the release of a milestone (FL-503) — read
 * from the agreement's snapshot there, so a later policy change never moves an agreed price.
 */
final class FreelanceFees
{
    /**
     * @param  array<string, mixed>|FreelancePolicyData  $policy  the merged values, or a snapshot's `values`
     * @return array{amount_minor: int, client_fee_minor: int, client_total_minor: int, commission_minor: int, freelancer_net_minor: int}
     */
    public static function breakdown(int $amountMinor, array|FreelancePolicyData $policy): array
    {
        $values = $policy instanceof FreelancePolicyData ? $policy->all() : $policy;
        $amount = max(0, $amountMinor);

        $clientFee = self::fee($amount, $values, 'client_fee');
        $commission = min($amount, self::fee($amount, $values, 'commission'));

        return [
            'amount_minor' => $amount,
            'client_fee_minor' => $clientFee,
            'client_total_minor' => $amount + $clientFee,
            'commission_minor' => $commission,
            'freelancer_net_minor' => $amount - $commission,
        ];
    }

    /** @param array<string, mixed> $values */
    private static function fee(int $amount, array $values, string $group): int
    {
        if ($amount === 0) {
            return 0;
        }

        $value = max(0, (int) ($values[$group.'.value'] ?? 0));
        $fee = ($values[$group.'.type'] ?? 'percent') === 'fixed'
            ? $value
            : intdiv($amount * min($value, FreelancePolicyData::MAX_BP) + 5000, 10000);

        $min = $values[$group.'.min_minor'] ?? null;
        $max = $values[$group.'.max_minor'] ?? null;
        if ($min !== null) {
            $fee = max($fee, (int) $min);
        }
        if ($max !== null) {
            $fee = min($fee, (int) $max);
        }

        return max(0, $fee);
    }
}
