<?php

namespace Modules\Rides\Http\Controllers\Hire;

use Modules\Rides\Models\DriverHire;

/** What the apps see of a «find a driver» request (O-26c). */
final class HirePresenter
{
    /** @return array<string, mixed> */
    public static function hire(DriverHire $hire, bool $forRider = true): array
    {
        $hire->loadMissing(['driver', 'user', 'currency']);
        $live = in_array($hire->status, DriverHire::LIVE, true);
        $driver = $hire->driver;

        return [
            'uuid' => $hire->uuid,
            'status' => $hire->status === 'unpaid' && $hire->payment_expires_at?->isPast() ? 'lapsed' : $hire->status,
            // O-67: held for the payment screen until then — the app opens it (purpose driver_hire, {booking_uuid}).
            'payment_expires_at' => $hire->status === 'unpaid' ? $hire->payment_expires_at?->toIso8601String() : null,
            'discount_minor' => (int) $hire->discount_minor,
            'pickup' => ['lat' => $hire->pickup_lat, 'lng' => $hire->pickup_lng, 'address' => $hire->pickup_address],
            'starts_at' => $hire->starts_at->toIso8601String(),
            // When the time booked runs out (from the start once started) — the apps count down on the device clock.
            'ends_at' => ($hire->started_at ?? $hire->starts_at)->copy()->addSeconds($hire->bookedSeconds())->toIso8601String(),
            'unit' => $hire->unit,
            'quantity' => $hire->quantity,
            'transmission' => $hire->transmission,
            'car' => $hire->car,
            'note' => $hire->note,
            'amount_minor' => $hire->amount_minor,
            'extra_minor' => $hire->extra_minor,
            'extra_due_minor' => $hire->extra_due_minor,
            'currency' => $hire->currency === null ? null : ['code' => $hire->currency->code, 'symbol' => $hire->currency->symbol, 'decimal_places' => (int) ($hire->currency->decimal_places ?? 2)],
            'started_at' => $hire->started_at?->toIso8601String(),
            'ended_at' => $hire->ended_at?->toIso8601String(),
            'canceled_at' => $hire->canceled_at?->toIso8601String(),
            'captain' => ! $forRider || $driver === null ? null : [
                'name' => $driver->name, 'avatar' => $driver->avatar_url ?? null,
                'phone' => $live ? $driver->phone : null,
            ],
            'rider' => $forRider ? null : [
                'name' => $hire->user?->name,
                'phone' => $live ? $hire->user?->phone : null,
            ],
        ];
    }
}
