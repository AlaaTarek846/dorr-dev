<?php

namespace Modules\Rides\Http\Controllers\Marine;

use Modules\Rides\Models\MarineBooking;
use Modules\Rides\Models\MarineListing;

/** What the apps see of a sea trip / a yacht hire and its bookings (O-25). */
final class MarinePresenter
{
    /** @return array<string, mixed> */
    public static function listing(MarineListing $listing, bool $withSlots = false): array
    {
        $listing->loadMissing(['vehicle.make.translations', 'vehicle.model.translations', 'vehicle.color.translations', 'driver', 'currency']);
        $vehicle = $listing->vehicle;
        $driver = $listing->driver;

        return [
            'uuid' => $listing->uuid,
            'kind' => $listing->kind,
            'status' => $listing->status,
            'title' => $listing->title,
            'description' => $listing->description,
            'marina' => ['lat' => $listing->marina_lat, 'lng' => $listing->marina_lng, 'address' => $listing->marina_address],
            'route_note' => $listing->route_note,
            'amenities' => array_values((array) $listing->amenities),
            'capacity' => $listing->capacity,
            'seats_left' => $listing->kind === 'trip' ? $listing->seatsLeft() : null,
            'depart_at' => $listing->depart_at?->toIso8601String(),
            'duration_min' => $listing->duration_min,
            'prices' => [
                'seat_minor' => $listing->seat_price_minor,
                'private_minor' => $listing->private_price_minor,
                'hour_minor' => $listing->hour_price_minor,
                'min_hours' => $listing->min_hours,
            ],
            'currency' => $listing->currency === null ? null : ['code' => $listing->currency->code, 'symbol' => $listing->currency->symbol, 'decimal_places' => (int) ($listing->currency->decimal_places ?? 2)],
            // A hire: the times already taken, so the guest picks a free one.
            'busy' => $withSlots && $listing->kind === 'rental'
                ? $listing->bookings()->whereIn('status', ['confirmed', 'boarded'])->where('ends_at', '>', now())->orderBy('starts_at')->limit(60)->get(['starts_at', 'ends_at'])
                    ->map(fn (MarineBooking $b) => ['from' => $b->starts_at->toIso8601String(), 'to' => $b->ends_at->toIso8601String()])->values()
                : null,
            'boat' => $vehicle === null ? null : [
                'type' => $vehicle->body_type instanceof \BackedEnum ? $vehicle->body_type->value : $vehicle->body_type,
                'make' => $vehicle->make?->translated('name'),
                'model' => $vehicle->model?->translated('name'),
                'color' => $vehicle->color?->translated('name'),
                'seats' => (int) $vehicle->seats,
            ],
            'captain' => $driver === null ? null : ['name' => $driver->name, 'avatar' => $driver->avatar_url ?? null],
        ];
    }

    /** @return array<string, mixed> */
    public static function booking(MarineBooking $booking, bool $forGuest = true): array
    {
        $booking->loadMissing(['listing', 'user']);
        $live = in_array($booking->status, ['confirmed', 'boarded'], true);

        return [
            'uuid' => $booking->uuid,
            'mode' => $booking->mode,
            'status' => $booking->status === 'unpaid' && $booking->payment_expires_at?->isPast() ? 'lapsed' : $booking->status,
            // O-67: held for the payment screen until then — the app opens it (purpose marine_booking, {booking_uuid}).
            'payment_expires_at' => $booking->status === 'unpaid' ? $booking->payment_expires_at?->toIso8601String() : null,
            'discount_minor' => (int) $booking->discount_minor,
            'guests' => $booking->guests,
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'amount_minor' => $booking->amount_minor,
            'extension_minor' => $booking->extension_minor,
            'extension' => $booking->extension,
            'qr' => $forGuest && $live ? $booking->qr_token : null,
            'created_at' => $booking->created_at?->toIso8601String(),
            'canceled_at' => $booking->canceled_at?->toIso8601String(),
            'listing' => $forGuest ? self::listing($booking->listing) + [
                'captain_phone' => $live ? $booking->listing->driver?->phone : null,
            ] : null,
            'guest' => $forGuest ? null : [
                'name' => $booking->user?->name,
                'phone' => $live ? $booking->user?->phone : null,
            ],
        ];
    }
}
