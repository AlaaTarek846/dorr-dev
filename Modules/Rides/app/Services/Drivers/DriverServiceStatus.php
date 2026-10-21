<?php

namespace Modules\Rides\Services\Drivers;

use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideSafetyIncident;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\Wallet\Enums\WithdrawalRequestStatus;
use Modules\Wallet\Models\WithdrawalRequest;

/**
 * The out-of-the-ordinary states a captain meets (docs/rides_tasks.md D-329..D-344): maintenance, the
 * financial safe mode, the service or a category switched off where they work, an emergency review after
 * a rider's SOS, and how their last withdrawal stands (pending, late with no result yet, rejected).
 * The app reads it once on open / reconnect and again on a `driver.changed` event — never on a timer.
 */
class DriverServiceStatus
{
    public function __construct(
        private readonly RidePolicies $policies,
        private readonly RideFeatures $features,
    ) {}

    /** D-329: the captain side is under maintenance in this country — nobody goes online. */
    public function maintenance(Driver $driver): bool
    {
        return $driver->country_id !== null && $this->policies->for((int) $driver->country_id, $driver->city_id)->bool('ops.driver_maintenance');
    }

    /** D-330: money leaves no captain wallet (withdrawals, new payout accounts) until the switch is off. */
    public function safeMode(Driver $driver): bool
    {
        return $driver->country_id !== null && $this->policies->for((int) $driver->country_id, $driver->city_id)->bool('ops.financial_safe_mode');
    }

    /** D-336: the rides service is switched off where the captain works. */
    public function serviceOff(Driver $driver): bool
    {
        return $driver->country_id !== null && ! $this->features->allows((int) $driver->country_id, $driver->city_id, RideFeatures::SERVICE);
    }

    /**
     * D-344: a rider pressed SOS on one of this captain's rides and the safety team has not closed it —
     * the captain stays offline for `safety.review_hold_hours` at most (0 = never held).
     */
    public function emergencyReview(Driver $driver): ?RideSafetyIncident
    {
        $hours = $driver->country_id !== null ? $this->policies->for((int) $driver->country_id)->int('safety.review_hold_hours') : 0;
        if ($hours <= 0) {
            return null;
        }

        return RideSafetyIncident::query()
            ->where('type', 'sos')->where('reporter_type', 'user')->whereNull('resolved_at')
            ->where('created_at', '>=', now()->subHours($hours))
            ->whereHas('ride', fn ($q) => $q->where('driver_id', $driver->id))
            ->latest('id')->first();
    }

    /** Called before money leaves a captain wallet (WithdrawalGuards). */
    public function assertMoneyMoves(Driver $driver): void
    {
        if ($this->safeMode($driver)) {
            throw new RidesException('financial_safe_mode', 423);
        }
    }

    /** @return array<string, mixed> */
    public function show(Driver $driver): array
    {
        $review = $this->emergencyReview($driver);
        $hours = $driver->country_id !== null ? $this->policies->for((int) $driver->country_id)->int('safety.review_hold_hours') : 0;

        return [
            'maintenance' => $this->maintenance($driver),
            'maintenance_message' => $this->maintenance($driver) ? (trim((string) $this->policies->for((int) $driver->country_id)->get('ops.driver_maintenance_message')) ?: __('rides.service_status.maintenance')) : null,
            'safe_mode' => $this->safeMode($driver),
            'service_on' => ! $this->serviceOff($driver),
            'paused_categories' => $this->pausedCategories($driver),
            'emergency_review' => $review ? [
                'ride_number' => $review->ride?->number,
                'since' => $review->created_at?->toIso8601String(),
                'until' => $review->created_at?->copy()->addHours($hours)->toIso8601String(),
            ] : null,
            'last_withdrawal' => $this->lastWithdrawal($driver),
        ];
    }

    /** D-335: categories this captain works in that are switched off where they are. @return list<array{slug: string, name: string}> */
    private function pausedCategories(Driver $driver): array
    {
        if ($driver->country_id === null) {
            return [];
        }
        $gate = app(DriverEligibility::class);

        return RideCategory::query()->orderBy('sort_order')->get()
            ->filter(fn (RideCategory $c) => $gate->wants($driver, $c))
            ->filter(fn (RideCategory $c) => ! $c->status || ! $this->features->allows((int) $driver->country_id, $driver->city_id, RideFeatures::categoryFeature($c->slug)))
            ->map(fn (RideCategory $c) => ['slug' => $c->slug, 'name' => $c->translatedName() ?? $c->slug])
            ->values()->all();
    }

    /** D-338..D-340: pending, late (no result yet — support can chase it), approved, or rejected with the reason. */
    private function lastWithdrawal(Driver $driver): ?array
    {
        $w = WithdrawalRequest::query()
            ->whereHas('wallet', fn ($q) => $q->where('owner_type', 'driver')->where('owner_id', $driver->id))
            ->latest('id')->first();
        if ($w === null) {
            return null;
        }
        $sla = $driver->country_id !== null ? $this->policies->for((int) $driver->country_id)->int('wallet.payout_sla_hours') : 0;
        $state = match ($w->status) {
            WithdrawalRequestStatus::Pending => $sla > 0 && $w->created_at !== null && $w->created_at->lt(now()->subHours($sla)) ? 'unknown' : 'pending',
            WithdrawalRequestStatus::Approved => 'paid',
            WithdrawalRequestStatus::Rejected => 'failed',
        };

        return [
            'id' => $w->id,
            'state' => $state,
            'amount_minor' => (int) $w->amount_minor,
            'reason' => $w->rejection_reason,
            'created_at' => $w->created_at?->toIso8601String(),
            'reviewed_at' => $w->reviewed_at?->toIso8601String(),
        ];
    }
}
