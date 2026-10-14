<?php

namespace Modules\Rides\Services\Pricing;

/**
 * A priced ride in minor units (docs/rides-plan.md §7.2–7.3): what the rider pays, what the
 * driver keeps, and the lines each of them sees.
 */
final class FareBreakdown
{
    /**
     * @param  list<array{code: string, amount_minor: int, visible_to: string, meta?: array<string, mixed>}>  $lines
     */
    public function __construct(
        public readonly int $fare,
        public readonly int $waiting,
        public readonly int $stopsWait,
        public readonly int $commission,
        public readonly int $tax,
        public readonly int $previousBalance,
        public readonly int $total,
        public readonly int $driverNet,
        public readonly array $lines,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fare' => $this->fare,
            'waiting' => $this->waiting,
            'stops_wait' => $this->stopsWait,
            'commission' => $this->commission,
            'tax' => $this->tax,
            'previous_balance' => $this->previousBalance,
            'total' => $this->total,
            'driver_net' => $this->driverNet,
            'lines' => $this->lines,
        ];
    }
}
