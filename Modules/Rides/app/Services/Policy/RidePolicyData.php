<?php

namespace Modules\Rides\Services\Policy;

use Modules\Rides\Enums\PricingMode;
use Modules\Rides\Exceptions\RidesException;

/**
 * The merged numbers for one place and category (docs/rides-structure.md §12): every key from
 * `rides.policy_defaults`, overridden by each matching policy row from the broadest scope to the
 * most specific. Read-only; a ride copies `all()` into its confirmed snapshot so a later change
 * never touches it (D-371).
 */
final class RidePolicyData
{
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
        return (array) config('rides.policy_defaults', []);
    }

    /**
     * Refuses unknown keys and values of the wrong kind before a policy row is saved.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RidesException
     */
    public static function validate(array $data): void
    {
        $defaults = self::defaults();
        $bad = [];

        foreach ($data as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                $bad[] = (string) $key;

                continue;
            }

            $default = $defaults[$key];
            $ok = match (true) {
                $key === 'pricing_mode' => is_string($value) && PricingMode::tryFrom($value) !== null,
                $key === 'gps_gap.strategy' => in_array($value, ['route', 'straight'], true),
                $key === 'price.commission_type' => in_array($value, ['percent', 'fixed'], true),
                is_bool($default) => is_bool($value),
                is_int($default) => is_int($value),
                is_array($default) => is_array($value) && array_is_list($value) && collect($value)->every(fn ($v) => is_int($v) && $v > 0),
                default => is_string($value),
            };

            // Rates, money and times are never negative — except the driver's minimum balance (a debt limit).
            if ($ok && is_int($value) && $value < 0 && $key !== 'driver.min_balance_minor') {
                $ok = false;
            }

            if (! $ok) {
                $bad[] = (string) $key;
            }
        }

        if ($bad !== []) {
            throw RidesException::invalidPolicy($bad);
        }
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function pricingMode(): PricingMode
    {
        return PricingMode::from((string) $this->get('pricing_mode'));
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
