<?php

namespace Modules\Rides\Services\Payment;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Rides\Models\LessonEnrollment;
use Modules\Rides\Services\Lessons\DrivingLessonService;

/** O-67: paying for it on the app's one payment screen (ServiceBookingPurpose). */
class LessonPackPurpose extends ServiceBookingPurpose
{
    public const KEY = 'lesson_pack';

    protected function model(): string
    {
        return LessonEnrollment::class;
    }

    protected function country(Model $booking): ?Country
    {
        return $booking->pack?->country;
    }

    protected function title(Model $booking): string
    {
        return __('rides.checkout.lessons', ['title' => (string) $booking->pack?->title]);
    }

    protected function subtitle(Model $booking): ?string
    {
        return null;
    }

    protected function confirm(Model $booking, int $payable, int $discount): void
    {
        app(DrivingLessonService::class)->confirmPaid($booking, $payable, $discount);
    }
}
