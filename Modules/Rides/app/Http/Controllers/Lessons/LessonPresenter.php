<?php

namespace Modules\Rides\Http\Controllers\Lessons;

use Modules\Rides\Models\LessonEnrollment;
use Modules\Rides\Models\LessonPack;
use Modules\Rides\Models\LessonSession;

/** What the apps see of driving lessons (O-26b). */
final class LessonPresenter
{
    /** @return array<string, mixed> */
    public static function pack(LessonPack $pack): array
    {
        $pack->loadMissing(['driver', 'currency']);
        $driver = $pack->driver;

        return [
            'uuid' => $pack->uuid,
            'status' => $pack->status,
            'title' => $pack->title,
            'description' => $pack->description,
            'transmission' => $pack->transmission,
            'lessons' => $pack->lessons,
            'minutes_per_lesson' => $pack->minutes_per_lesson,
            'price_minor' => $pack->price_minor,
            'per_lesson_minor' => intdiv($pack->price_minor, max(1, $pack->lessons)),
            'own_car' => $pack->own_car,
            'area' => ['lat' => $pack->area_lat, 'lng' => $pack->area_lng, 'address' => $pack->area_address],
            'currency' => $pack->currency === null ? null : ['code' => $pack->currency->code, 'symbol' => $pack->currency->symbol, 'decimal_places' => (int) ($pack->currency->decimal_places ?? 2)],
            'learners' => $pack->enrollments_count ?? null,
            'trainer' => $driver === null ? null : ['name' => $driver->name, 'avatar' => $driver->avatar_url ?? null, 'gender' => $driver->gender ?? null],
        ];
    }

    /** @return array<string, mixed> */
    public static function enrollment(LessonEnrollment $e): array
    {
        $e->loadMissing(['pack', 'sessions', 'driver']);

        return [
            'uuid' => $e->uuid,
            'status' => $e->status === 'unpaid' && $e->payment_expires_at?->isPast() ? 'lapsed' : $e->status,
            // O-67: held for the payment screen until then — the app opens it (purpose lesson_pack, {booking_uuid}).
            'payment_expires_at' => $e->status === 'unpaid' ? $e->payment_expires_at?->toIso8601String() : null,
            'discount_minor' => (int) $e->discount_minor,
            'lessons_total' => $e->lessons_total,
            'lessons_done' => $e->lessons_done,
            'lessons_left' => $e->lessonsLeft(),
            'amount_minor' => $e->amount_minor,
            'refunded_minor' => $e->refunded_minor,
            'pack' => self::pack($e->pack),
            'trainer_phone' => $e->status === 'active' ? $e->driver?->phone : null,
            'sessions' => $e->sessions->sortBy('number')->map(fn (LessonSession $s) => self::session($s))->values(),
        ];
    }

    /** @return array<string, mixed> */
    public static function session(LessonSession $s, bool $forTrainer = false): array
    {
        $out = [
            'uuid' => $s->uuid,
            'number' => $s->number,
            'status' => $s->status,
            'starts_at' => $s->starts_at->toIso8601String(),
            'pickup' => ['lat' => $s->pickup_lat, 'lng' => $s->pickup_lng, 'address' => $s->pickup_address],
            'done_at' => $s->done_at?->toIso8601String(),
        ];
        if ($forTrainer) {
            $e = $s->enrollment;
            $out += [
                'learner' => ['name' => $e?->user?->name, 'phone' => $e?->user?->phone],
                'pack' => ['title' => $e?->pack?->title, 'minutes_per_lesson' => $e?->pack?->minutes_per_lesson, 'transmission' => $e?->pack?->transmission, 'lessons' => $e?->lessons_total],
            ];
        }

        return $out;
    }
}
