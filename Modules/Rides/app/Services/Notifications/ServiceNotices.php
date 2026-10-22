<?php

namespace Modules\Rides\Services\Notifications;

use App\Models\Currency;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Model;

/**
 * What the booked services (bus, boats, a driver, lessons) tell people that's the same everywhere:
 * the captain's earnings landing in the wallet, a booking whose payment time ran out. The event names
 * keep each service's prefix (`bus.`, `marine.`, `hire.`, `lesson.`) so its screens reload on them.
 */
class ServiceNotices
{
    public function __construct(private readonly NotificationCenter $center) {}

    /** The captain's share is in the wallet (withdrawable). */
    public function earning(?Model $captain, string $prefix, int $amountMinor, ?int $currencyId, string $what, array $data = []): void
    {
        if ($captain === null || $amountMinor <= 0) {
            return;
        }
        $this->center->send($captain, $prefix.'.earning', 'service_earning_title', 'service_earning_body', [
            'amount' => $this->money($amountMinor, $currencyId), 'what' => $what,
        ], ['type' => 'service_earning'] + $data);
    }

    /** A booking held for its payment was never paid: it's gone and what it held is free again. */
    public function lapsed(?Model $owner, string $prefix, string $uuid, string $what): void
    {
        if ($owner === null) {
            return;
        }
        $this->center->send($owner, $prefix.'.lapsed', 'service_lapsed_title', 'service_lapsed_body', ['what' => $what], [
            'type' => 'service_lapsed', 'booking_uuid' => $uuid,
        ]);
    }

    /** A reminder shortly before it starts — once (the sweep keeps track). */
    public function reminder(?Model $person, string $prefix, string $what, string $time, array $data = []): void
    {
        if ($person === null) {
            return;
        }
        $this->center->send($person, $prefix.'.reminder', 'service_reminder_title', 'service_reminder_body', [
            'what' => $what, 'time' => $time,
        ], ['type' => 'service_reminder'] + $data, true, ['priority' => 10]);
    }

    public function money(int $minor, ?int $currencyId): string
    {
        $currency = $currencyId !== null ? Currency::query()->find($currencyId) : null;
        $places = (int) ($currency?->decimal_places ?? 2);

        return number_format($minor / (10 ** $places), $places).' '.($currency?->symbol ?? '');
    }
}
