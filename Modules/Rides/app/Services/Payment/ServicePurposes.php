<?php

namespace Modules\Rides\Services\Payment;

/** O-67: the payment screen's purposes for the rides' booked services (ServiceBookingPurpose). */
final class ServicePurposes
{
    /** @var array<string, class-string<ServiceBookingPurpose>> */
    public const ALL = [
        BusBookingPurpose::KEY => BusBookingPurpose::class,
        MarineBookingPurpose::KEY => MarineBookingPurpose::class,
        DriverHirePurpose::KEY => DriverHirePurpose::class,
        LessonPackPurpose::KEY => LessonPackPurpose::class,
    ];
}
