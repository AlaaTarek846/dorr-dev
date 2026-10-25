<?php

namespace Modules\Rides\Services\Ai;

use Carbon\CarbonImmutable;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\PlaceSearch;
use Modules\User\Models\Address;
use Modules\User\Models\User;

/**
 * «دُرر يفهم مقصدك» and the booking assistant (Block 06 phase 1 — R-INN-02, AI-14): the rider says or
 * writes the trip in their own words — «ودّيني المطار بكرة الساعة 8 الصبح، اتنين، كاش» — and DORR AI
 * turns it into what the booking sheet needs: the destination, the category, the time, how many ride
 * and how to pay.
 *
 * The places come from the admin's map provider (Google or Mapbox, {@see PlaceSearch}) or the rider's
 * own saved addresses — never invented by the model. Nothing is booked here: the app shows the place on
 * the map for the rider to confirm or correct, then prices and books the usual way. A vague request
 * comes back with one question (`clarify`) instead of a guess.
 */
class SmartDestination
{
    private const MAX_PLACES = 6;

    public function __construct(
        private readonly RideAi $ai,
        private readonly PlaceSearch $places,
        private readonly RideFeatures $features,
    ) {}

    /**
     * @return array{understood: array<string, mixed>, clarify: ?string, places: list<array<string, mixed>>, confirm_on_map: bool}
     */
    public function understand(User $rider, string $text, ?float $lat, ?float $lng, int $countryId, ?int $cityId): array
    {
        if (! $this->features->allows($countryId, $cityId, 'ai_booking')) {
            throw RidesException::featureDisabled('ai_booking');
        }
        $lang = app()->getLocale();
        $tz = (string) config('rides.default_timezone', 'Asia/Riyadh');
        $now = CarbonImmutable::now($tz);

        $snapshot = $this->features->snapshot($countryId, $cityId);
        $open = array_keys(array_filter((array) ($snapshot['categories'] ?? [])));
        $categories = RideCategory::query()->whereIn('slug', $open)->with('translations')->get()
            ->mapWithKeys(fn (RideCategory $c) => [$c->slug => (string) $c->translated('name')])->all();
        $saved = Address::query()->where('user_id', $rider->id)->whereNotNull('latitude')->whereNotNull('longitude')->latest('id')->limit(20)->get();

        $system = implode("\n", [
            'You help a rider book a car ride in the DORR app. Read the rider\'s request (Arabic, any dialect, or English) and answer with ONE JSON object only:',
            '{"destination": string|null, "saved": "home"|"work"|string|null, "category": string|null, "when": string|null, "passengers": integer|null, "payment": "wallet"|"cash"|"card"|null, "note": string|null, "clarify": string|null}',
            '- destination: the place to search on the map, as the rider would type it in a search box (name + area), in the rider\'s language. null if the rider means one of their saved places or did not say where.',
            '- saved: "home" or "work", or the exact title of one of the rider\'s favourite places below, when the rider means it.',
            '- category: one of these keys only, when the rider asks for one: '.json_encode($categories, JSON_UNESCAPED_UNICODE).'. Otherwise null.',
            '- when: ISO-8601 with offset for a ride later (now is '.$now->toIso8601String().', time zone '.$tz.'). null for now.',
            '- passengers: how many people ride, only if said. payment: only if said. note: a short pickup note for the captain only if the rider gave one (never a phone number).',
            '- clarify: ONE short question in the rider\'s language ('.($lang === 'ar' ? 'Arabic' : 'English').') when the destination is missing or could be several different places — then leave destination null.',
            '- Never invent addresses or coordinates. Never book. No text outside the JSON.',
            'The rider\'s favourite places: '.json_encode($saved->where('type', 'other')->pluck('title')->filter()->values()->all(), JSON_UNESCAPED_UNICODE),
        ]);

        $answer = $this->ai->ask($rider, $system, mb_substr(trim($text), 0, 500), 'rides.smart_destination');

        $understood = [
            'destination' => self::str($answer['destination'] ?? null, 120),
            'saved' => self::str($answer['saved'] ?? null, 60),
            'category' => isset($answer['category']) && isset($categories[$answer['category']]) ? (string) $answer['category'] : null,
            'when' => $this->future($answer['when'] ?? null),
            'passengers' => isset($answer['passengers']) && is_numeric($answer['passengers']) && (int) $answer['passengers'] >= 1 && (int) $answer['passengers'] <= 20 ? (int) $answer['passengers'] : null,
            'payment' => in_array($answer['payment'] ?? null, ['wallet', 'cash', 'card'], true) ? $answer['payment'] : null,
            'note' => self::note($answer['note'] ?? null),
        ];
        $understood['category_name'] = $understood['category'] === null ? null : $categories[$understood['category']];

        $places = [];
        if ($understood['saved'] !== null) {
            $address = match ($understood['saved']) {
                'home', 'work' => $saved->firstWhere('type', $understood['saved']),
                default => $saved->first(fn (Address $a) => $a->type === 'other' && mb_strtolower(trim((string) $a->title)) === mb_strtolower($understood['saved'])),
            };
            if ($address !== null) {
                $places[] = ['name' => (string) ($address->title ?: __('rides.ai.saved_'.($address->type === 'other' ? 'favorite' : $address->type))), 'address' => (string) ($address->address_details ?: $address->title ?: ''), 'lat' => (float) $address->latitude, 'lng' => (float) $address->longitude, 'source' => 'saved'];
            }
        }
        if ($understood['destination'] !== null) {
            foreach ($this->places->suggest($understood['destination'], $lat, $lng, $lang) as $place) {
                $places[] = $place + ['source' => 'map'];
            }
        }
        $places = array_slice($places, 0, self::MAX_PLACES);

        $clarify = self::str($answer['clarify'] ?? null, 200);
        if ($places === [] && $clarify === null) {
            $clarify = __('rides.ai.where_to');
        }

        return ['understood' => $understood, 'clarify' => $places === [] ? $clarify : null, 'places' => $places, 'confirm_on_map' => true];
    }

    /** A time later than now — a past or unreadable one means "now". */
    private function future(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            $at = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $at->isFuture() ? $at->toIso8601String() : null;
    }

    private static function str(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' || $value === 'null' ? null : mb_substr($value, 0, $max);
    }

    /** A pickup note follows the booking rule: no phone numbers or links (D-61). */
    private static function note(mixed $value): ?string
    {
        $note = self::str($value, 120);
        if ($note === null || preg_match('/(\d[\s-]?){7,}|[٠-٩]{7,}|https?:\/\/|www\./u', $note)) {
            return null;
        }

        return $note;
    }
}
