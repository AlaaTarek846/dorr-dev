<?php

namespace Modules\Rides\Services\Payment;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Rides\Models\MarineBooking;
use Modules\Rides\Services\Marine\MarineService;

/** O-67: paying for it on the app's one payment screen (ServiceBookingPurpose). */
class MarineBookingPurpose extends ServiceBookingPurpose
{
    public const KEY = 'marine_booking';

    protected function model(): string
    {
        return MarineBooking::class;
    }

    protected function country(Model $booking): ?Country
    {
        return $booking->listing?->country;
    }

    protected function title(Model $booking): string
    {
        return __('rides.checkout.marine', ['title' => (string) $booking->listing?->title]);
    }

    protected function subtitle(Model $booking): ?string
    {
        return $booking->starts_at?->copy()->setTimezone((string) config('rides.default_timezone', 'Asia/Riyadh'))->format('Y-m-d H:i');
    }

    protected function confirm(Model $booking, int $payable, int $discount): void
    {
        app(MarineService::class)->confirmPaid($booking, $payable, $discount);
    }
}
