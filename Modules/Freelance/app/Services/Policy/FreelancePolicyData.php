<?php

namespace Modules\Freelance\Services\Policy;

use App\Models\Currency;
use Modules\Freelance\Exceptions\FreelanceException;

/**
 * The merged freelance numbers for one country (and specialty) — every key of `freelance.policy_defaults`,
 * overridden by each matching policy row from the broadest scope to the most specific (FL-008).
 * Read-only; an agreement copies `snapshot()` so a later change never touches it (FL-401).
 *
 * Units: rates in basis points (1000 = 10%), money in the minor unit of the country's currency
 * (null = no limit), times in days.
 */
final class FreelancePolicyData
{
    public const MAX_BP = 10000;

    /**
     * Every key: its group (the admin screen's sections) and what it may hold.
     *   enum      — one of `options`
     *   amount    — `commission.value` / `client_fee.value`: basis points when its `*.type` is percent, money when fixed
     *   money     — minor units of the country's currency, or null for "no limit"
     *   int       — a whole number between `min` and `max`
     *   bool
     */
    public const KEYS = [
        'commission.type' => ['group' => 'commission', 'kind' => 'enum', 'options' => ['percent', 'fixed']],
        'commission.value' => ['group' => 'commission', 'kind' => 'amount', 'type_key' => 'commission.type'],
        'commission.min_minor' => ['group' => 'commission', 'kind' => 'money'],
        'commission.max_minor' => ['group' => 'commission', 'kind' => 'money'],
        'client_fee.type' => ['group' => 'client_fee', 'kind' => 'enum', 'options' => ['percent', 'fixed']],
        'client_fee.value' => ['group' => 'client_fee', 'kind' => 'amount', 'type_key' => 'client_fee.type'],
        'client_fee.min_minor' => ['group' => 'client_fee', 'kind' => 'money'],
        'client_fee.max_minor' => ['group' => 'client_fee', 'kind' => 'money'],
        'offers.free_monthly_quota' => ['group' => 'offers', 'kind' => 'int', 'min' => 0, 'max' => 10000],
        'offers.window_days' => ['group' => 'offers', 'kind' => 'int', 'min' => 1, 'max' => 365],
        'offers.max_per_project' => ['group' => 'offers', 'kind' => 'int', 'min' => 1, 'max' => 10000],
        'review.days' => ['group' => 'review', 'kind' => 'int', 'min' => 1, 'max' => 90],
        'review.auto_approve' => ['group' => 'review', 'kind' => 'bool'],
        'review.free_revisions' => ['group' => 'review', 'kind' => 'int', 'min' => 0, 'max' => 50],
        'milestones.max_count' => ['group' => 'milestones', 'kind' => 'int', 'min' => 1, 'max' => 50],
        'milestones.min_amount_minor' => ['group' => 'milestones', 'kind' => 'money'],
        'project.min_budget_minor' => ['group' => 'project', 'kind' => 'money'],
        'project.max_budget_minor' => ['group' => 'project', 'kind' => 'money'],
        'earnings.hold_days' => ['group' => 'earnings', 'kind' => 'int', 'min' => 0, 'max' => 365],
    ];

    /** Pairs that must not cross: [lower, upper]. */
    private const RANGES = [
        ['commission.min_minor', 'commission.max_minor'],
        ['client_fee.min_minor', 'client_fee.max_minor'],
        ['project.min_budget_minor', 'project.max_budget_minor'],
    ];

    /** Money cap: far above any real project, below what an int column holds. */
    private const MAX_MONEY = 1_000_000_000_000;

    /**
     * @param  array<string, mixed>  $values
     * @param  list<int>  $policyIds  The rows that contributed, broadest first.
     */
    private function __construct(
        private readonly array $values,
        public readonly array $policyIds,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $layers  Broadest first.
     * @param  list<int>  $policyIds
     */
    public static function merge(array $layers, array $policyIds = []): self
    {
        $values = self::defaults();
        foreach ($layers as $layer) {
            foreach ($layer as $key => $value) {
                if (array_key_exists($key, $values)) {
                    $values[$key] = $value;
                }
            }
        }

        return new self($values, $policyIds);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $configured = (array) config('freelance.policy_defaults', []);

        return array_intersect_key($configured, self::KEYS) + array_fill_keys(array_keys(self::KEYS), null);
    }

    /**
     * Refuses unknown keys and values of the wrong kind before a policy row is saved.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws FreelanceException freelance_policy_invalid, with the bad keys in `data.keys`
     */
    public static function validate(array $data): void
    {
        $bad = [];

        foreach ($data as $key => $value) {
            $meta = self::KEYS[$key] ?? null;
            $ok = $meta !== null && match ($meta['kind']) {
                'enum' => is_string($value) && in_array($value, $meta['options'], true),
                'bool' => is_bool($value),
                'int' => is_int($value) && $value >= $meta['min'] && $value <= $meta['max'],
                'money' => $value === null || (is_int($value) && $value >= 0 && $value <= self::MAX_MONEY),
                'amount' => is_int($value) && $value >= 0 && $value <= self::MAX_MONEY,
                default => false,
            };

            if (! $ok) {
                $bad[] = (string) $key;
            }
        }

        if ($bad !== []) {
            throw new FreelanceException('policy_invalid', 422, ['keys' => implode(', ', $bad)], ['keys' => $bad]);
        }
    }

    /**
     * The checks that need the whole picture (after merging): a percent can't pass 100%, and a lower
     * limit can't sit above its upper one.
     *
     * @throws FreelanceException
     */
    public function assertConsistent(): void
    {
        $bad = [];

        foreach (self::KEYS as $key => $meta) {
            if ($meta['kind'] === 'amount' && $this->values[$meta['type_key']] === 'percent' && $this->values[$key] > self::MAX_BP) {
                $bad[] = $key;
            }
        }
        foreach (self::RANGES as [$low, $high]) {
            if ($this->values[$low] !== null && $this->values[$high] !== null && $this->values[$low] > $this->values[$high]) {
                $bad[] = $low;
            }
        }

        if ($bad !== []) {
            throw new FreelanceException('policy_invalid', 422, ['keys' => implode(', ', $bad)], ['keys' => $bad]);
        }
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * What an agreement keeps (FL-401): the numbers and the currency they are in.
     *
     * @return array{currency: ?string, decimal_places: int, values: array<string, mixed>, policy_ids: list<int>}
     */
    public function snapshot(?Currency $currency): array
    {
        return [
            'currency' => $currency?->code,
            'decimal_places' => (int) ($currency?->decimal_places ?? 2),
            'values' => $this->values,
            'policy_ids' => $this->policyIds,
        ];
    }

    /** Keys whose value is an amount of money for these values (fixed fees and every `*_minor` key). */
    public static function isMoney(string $key, array $values): bool
    {
        $meta = self::KEYS[$key] ?? null;

        return $meta !== null && ($meta['kind'] === 'money' || ($meta['kind'] === 'amount' && ($values[$meta['type_key']] ?? null) === 'fixed'));
    }
}
