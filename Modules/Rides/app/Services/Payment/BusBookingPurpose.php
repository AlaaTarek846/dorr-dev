<?php

namespace Modules\Rides\Services\Payment;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Rides\Models\BusBooking;
use Modules\Rides\Services\Bus\BusService;

/** O-67: paying for it on the app's one payment screen (ServiceBookingPurpose). */
class BusBookingPurpose extends ServiceBookingPurpose
{
    public const KEY = 'bus_booking';

    protected function model(): string
    {
        return BusBooking::class;
    }

    protected function country(Model $booking): ?Country
    {
        return $booking->trip?->country;
    }

    protected function title(Model $booking): string
    {
        return __('rides.checkout.bus', ['seats' => max(count((array) ($booking->seats['outbound'] ?? [])), count((array) ($booking->seats['return'] ?? [])))]);
    }

    protected function subtitle(Model $booking): ?string
    {
        return app(BusService::class)->routeText($booking->trip);
    }

    protected function confirm(Model $booking, int $payable, int $discount): void
    {
        app(BusService::class)->confirmPaid($booking, $payable, $discount);
    }
}
