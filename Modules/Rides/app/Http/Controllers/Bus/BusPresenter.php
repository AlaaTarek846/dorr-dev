<?php

namespace Modules\Rides\Http\Controllers\Bus;

use Modules\Rides\Models\BusBooking;
use Modules\Rides\Models\BusTrip;

/** What the apps see of a bus trip, its seats and its tickets (O-20..O-24). */
final class BusPresenter
{
    /** @return array<string, mixed> */
    public static function trip(BusTrip $trip, ?string $leg = null, bool $withSeats = false): array
    {
        $trip->loadMissing(['vehicle.make.translations', 'vehicle.model.translations', 'vehicle.color.translations', 'driver', 'currency']);
        $vehicle = $trip->vehicle;
        $driver = $trip->driver;
        $free = fn (string $l) => max(0, $trip->seats_total - count($trip->takenSeats($l)));

        return [
            'uuid' => $trip->uuid,
            'status' => $trip->status,
            // On a search result: which way this match goes (the coming-back leg runs destination → origin).
            'leg' => $leg,
            'origin' => ['lat' => $trip->origin_lat, 'lng' => $trip->origin_lng, 'address' => $trip->origin_address],
            'destination' => ['lat' => $trip->destination_lat, 'lng' => $trip->destination_lng, 'address' => $trip->destination_address],
            'depart_at' => $trip->depart_at?->toIso8601String(),
            'return_at' => $trip->return_at?->toIso8601String(),
            'prices' => [
                'outbound_minor' => $trip->seatPrice('outbound'),
                'return_minor' => $trip->seatPrice('return'),
                'round_minor' => $trip->seatPrice('round'),
            ],
            'currency' => $trip->currency === null ? null : ['code' => $trip->currency->code, 'symbol' => $trip->currency->symbol, 'decimal_places' => (int) ($trip->currency->decimal_places ?? 2)],
            'seats_total' => $trip->seats_total,
            'seats_per_row' => $trip->seats_per_row,
            'free' => ['outbound' => $free('outbound'), 'return' => $trip->hasReturn() ? $free('return') : null],
            'taken' => $withSeats ? ['outbound' => $trip->takenSeats('outbound'), 'return' => $trip->hasReturn() ? $trip->takenSeats('return') : []] : null,
            'note' => $trip->note,
            'bus' => $vehicle === null ? null : [
                'make' => $vehicle->make?->translated('name'),
                'model' => $vehicle->model?->translated('name'),
                'color' => $vehicle->color?->translated('name'),
                'color_hex' => $vehicle->color?->hex,
                'plate' => trim($vehicle->plate_letters.' '.$vehicle->plate_number),
                'seats' => (int) $vehicle->seats,
            ],
            'captain' => $driver === null ? null : ['name' => $driver->name, 'avatar' => $driver->avatar_url ?? null],
        ];
    }

    /** @return array<string, mixed> */
    public static function booking(BusBooking $booking, bool $forRider = true): array
    {
        $booking->loadMissing(['trip', 'seatRows']);

        return [
            'uuid' => $booking->uuid,
            'status' => $booking->status,
            'trip_type' => $booking->trip_type,
            'seats' => $booking->seats,
            'amount_minor' => $booking->amount_minor,
            // One QR per seat per leg — what the captain scans at boarding (gone once cancelled).
            'tickets' => $booking->seatRows->map(fn ($s) => [
                'leg' => $s->leg, 'seat' => $s->seat_no, 'qr' => $forRider ? $s->qr_token : null, 'boarded' => $s->boarded_at !== null,
            ])->values(),
            'created_at' => $booking->created_at?->toIso8601String(),
            'canceled_at' => $booking->canceled_at?->toIso8601String(),
            'trip' => $forRider ? self::trip($booking->trip) + [
                // O-24: the rider can call or message the captain about this trip.
                'captain_phone' => in_array($booking->status, ['confirmed'], true) ? $booking->trip->driver?->phone : null,
            ] : null,
        ];
    }

    /**
     * O-24: who booked, with their seats and how to reach them — for the captain's trip page.
     *
     * @return list<array<string, mixed>>
     */
    public static function passengers(BusTrip $trip): array
    {
        return $trip->bookings()->whereIn('status', ['confirmed', 'completed'])->with(['user', 'seatRows'])->orderBy('id')->get()
            ->map(fn (BusBooking $b) => [
                'booking_uuid' => $b->uuid,
                'name' => $b->user?->name,
                'phone' => $b->user?->phone,
                'trip_type' => $b->trip_type,
                'seats' => $b->seatRows->map(fn ($s) => ['leg' => $s->leg, 'seat' => $s->seat_no, 'boarded' => $s->boarded_at !== null])->values(),
                'amount_minor' => $b->amount_minor,
            ])->values()->all();
    }
}
