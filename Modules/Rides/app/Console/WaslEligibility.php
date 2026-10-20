<?php

namespace Modules\Rides\Console;

use App\Models\Country;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Console\Command;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\RegulatorStatus;
use Modules\Rides\Models\DriverRegulatorStatus;
use Modules\Rides\Models\RideRegulatorCity;
use Modules\Rides\Services\Regulator\Wasl\WaslDriverService;
use Throwable;

/**
 * Daily at 00:01 (Jawad): every driver of a Wasl country is checked again, and drivers whose
 * approval ends in `expiry_warning_days` are told to register again.
 */
class WaslEligibility extends Command
{
    protected $signature = 'rides:wasl-eligibility';

    protected $description = 'Check every driver\'s eligibility at Wasl and warn about approvals about to end';

    public function handle(WaslDriverService $wasl, NotificationCenter $notifications): int
    {
        $countries = Country::query()->whereIn('code', array_keys((array) config('rides.regulators'), RideRegulatorCity::WASL, true))->pluck('id');
        $checked = 0;

        Driver::query()->whereIn('country_id', $countries)->whereNotNull('national_id')->where('national_id', '!=', '')
            ->chunkById(100, function ($drivers) use ($wasl, &$checked) {
                foreach ($drivers as $driver) {
                    try {
                        $wasl->check($driver);
                        $checked++;
                    } catch (Throwable $e) {
                        $this->warn("Driver {$driver->id}: ".$e->getMessage());
                    }
                }
            });

        $day = now()->addDays((int) config('rides.wasl.expiry_warning_days', 30))->toDateString();
        DriverRegulatorStatus::query()->where('regulator', RideRegulatorCity::WASL)
            ->where('driver_status', RegulatorStatus::Valid->value)->whereDate('driver_expires_at', $day)
            ->with('driver')->get()
            ->each(fn (DriverRegulatorStatus $s) => $s->driver && $notifications->send($s->driver, 'wasl.expiring', 'ride_wasl_expiring_title', 'ride_wasl_expiring_body', ['date' => $day], ['type' => 'wasl']));

        $this->info("{$checked} driver(s) checked at Wasl.");

        return self::SUCCESS;
    }
}
