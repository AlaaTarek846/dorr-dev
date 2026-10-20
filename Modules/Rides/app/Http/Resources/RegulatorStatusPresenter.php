<?php

namespace Modules\Rides\Http\Resources;

use Modules\Driver\Models\Driver;
use Modules\Rides\Models\DriverRegulatorStatus;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Regulator\Wasl\WaslDriverService;

/** A driver's standing at the regulator, the same for the driver app and the dashboard (D-9). */
final class RegulatorStatusPresenter
{
    /** @return array<string, mixed> */
    public static function present(Driver $driver, DriverRegulatorStatus $status, DriverEligibility $eligibility): array
    {
        $blocked = $status->blocked_until !== null && $status->blocked_until->isFuture();
        $reasons = fn (?array $codes, string $about) => collect($codes ?? [])->map(fn (string $code) => ['code' => $code, 'about' => $about, 'message' => WaslDriverService::reason($code)]);

        return [
            'regulator' => $status->regulator,
            'required' => true,
            'driver_status' => $status->driver_status,
            'vehicle_status' => $status->vehicle_status,
            'driver_expires_at' => $status->driver_expires_at?->toDateString(),
            'vehicle_expires_at' => $status->vehicle_expires_at?->toDateString(),
            'vehicle_expires_at_hijri' => $status->vehicle_expires_at_hijri,
            'criminal_record_status' => $status->criminal_record_status,
            'reasons' => $reasons($status->rejection_codes, 'driver')->merge($reasons($status->vehicle_rejection_codes, 'vehicle'))->values(),
            'blocked_until' => $blocked ? $status->blocked_until->toDateString() : null,
            'can_register' => ! $blocked,
            'last_checked_at' => $status->last_checked_at?->toIso8601String(),
            'last_registered_at' => $status->last_registered_at?->toIso8601String(),
            'registration_attempts' => $status->registration_attempts,
            // What keeps the driver offline right now, if anything (the same code the presence screen gets).
            'work_problem' => $eligibility->reasonNotToWork($driver),
        ];
    }
}
