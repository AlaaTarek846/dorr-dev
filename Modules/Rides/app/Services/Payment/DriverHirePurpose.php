<?php

namespace Modules\Rides\Services\Payment;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Rides\Models\DriverHire;
use Modules\Rides\Services\Hire\DriverHireService;

/** O-67: paying for it on the app's one payment screen (ServiceBookingPurpose). */
class DriverHirePurpose extends ServiceBookingPurpose
{
    public const KEY = 'driver_hire';

    protected function model(): string
    {
        return DriverHire::class;
    }

    protected function country(Model $booking): ?Country
    {
        return $booking->country;
    }

    protected function title(Model $booking): string
    {
        return __('rides.checkout.hire');
    }

    protected function subtitle(Model $booking): ?string
    {
        return $booking->starts_at?->copy()->setTimezone((string) config('rides.default_timezone', 'Asia/Riyadh'))->format('Y-m-d H:i');
    }

    protected function confirm(Model $booking, int $payable, int $discount): void
    {
        app(DriverHireService::class)->confirmPaid($booking, $payable, $discount);
    }
}
