<?php

namespace Modules\Rides\Services\Ai;

use App\Services\General\CountryResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\AI\Contracts\AiServiceActionProvider;
use Modules\AI\Support\ArabicTextNormalizer;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\User\Models\User;
use Throwable;

/**
 * DORR rides inside DORR AI's chat: "عايز أروح مطار القاهرة" → the answer recommends booking with DORR
 * (never another ride app) and a «احجز مشوارك مع دُرر» card comes under it with what «دُرر يفهم مقصدك»
 * understood — the place on the admin's map (Google or Mapbox), the category, the time, how many ride, how
 * to pay. The app opens rides with all of it filled in and the place on the map to confirm; nothing is
 * booked from the chat.
 *
 * Only where rides are on for the person's country. Recognising a trip is a word list (free, before every
 * answer); the place itself is worked out only for those messages, through the same plan-guarded DORR AI.
 */
class RideBookingSuggestion implements AiServiceActionProvider
{
    /** Words that say "I want to go somewhere" (normalised Arabic — ا for أ/إ/آ, ه for ة, ي for ى — and English). */
    private const TRIP_WORDS = [
        'اروح', 'اروّح', 'روحني', 'وديني', 'توديني', 'يوديني', 'وصلني', 'توصلني', 'يوصلني', 'خدني', 'تاخدني', 'مشوار', 'توصيله',
        'تاكسي', 'تكسي', 'اوبر', 'كريم', 'رايح', 'رايحه', 'ابغي اروح', 'ابي اروح', 'بدي روح', 'اطلع علي', 'محتاج عربيه', 'عايز عربيه',
        'take me to', 'drive me to', 'want to go to', 'need to go to', 'how do i get to', 'ride to', 'taxi', 'uber', 'careem',
        'a ride', 'book a car', 'need a car',
    ];

    public function __construct(
        private readonly RideFeatures $features,
        private readonly SmartDestination $smart,
        private readonly CountryResolver $countries,
    ) {}

    public function guidance(Authenticatable $owner, string $message): ?string
    {
        if ($this->countryFor($owner, $message) === null) {
            return null;
        }

        return implode(' ', [
            'The person wants to go somewhere. DORR has its own ride service inside this app (DORR Rides).',
            'In your answer, briefly acknowledge the trip and recommend booking it with DORR: a card titled «'.__('rides.ai.card_book_title').'» with the destination already understood appears right under your answer, and it opens the booking with everything filled in for them to confirm. Call the card by that exact title, never by an English name.',
            'Never recommend or mention other ride-hailing apps (Uber, Careem, inDrive, Bolt…), and never invent prices or arrival times — the booking screen shows the real ones.',
            'Keep it short and answer in the person\'s language.',
        ]);
    }

    public function actions(Authenticatable $owner, string $message): array
    {
        $countryId = $this->countryFor($owner, $message);
        if ($countryId === null) {
            return [];
        }

        $understood = null;
        // «دُرر يفهم مقصدك» where it is switched on: the place on the map, and the rest of the sentence.
        if ($this->features->allows($countryId, null, 'ai_booking')) {
            try {
                $understood = $this->smart->understand($owner, $message, null, null, $countryId, null);
            } catch (Throwable) {
                $understood = null; // the card still opens rides with the words, nothing filled in
            }
        }
        $places = array_slice($understood['places'] ?? [], 0, 3);
        $first = $places[0] ?? null;

        return [[
            'type' => 'ride_booking',
            'service' => 'passenger_ride',
            'title' => __('rides.ai.card_book_title'),
            'subtitle' => $first !== null ? (string) ($first['name'] ?? $first['address'] ?? '') : __('rides.ai.card_book_sub'),
            'text' => mb_substr(trim($message), 0, 500),
            'destination' => $first,
            'places' => $places,
            'understood' => $understood['understood'] ?? null,
            'clarify' => $understood['clarify'] ?? null,
        ]];
    }

    /** The country rides would run in for this person, when this message is about a trip and rides are on there. */
    private function countryFor(Authenticatable $owner, string $message): ?int
    {
        if (! $owner instanceof User || ! self::aboutATrip($message)) {
            return null;
        }
        $countryId = $owner->country_id ?? rescue(fn () => $this->countries->resolve(request())->id, null, false);
        if ($countryId === null || ! $this->features->allows((int) $countryId, null, 'rides')) {
            return null;
        }

        return (int) $countryId;
    }

    public static function aboutATrip(string $message): bool
    {
        $text = ' '.mb_strtolower(ArabicTextNormalizer::normalize($message)).' ';
        foreach (self::TRIP_WORDS as $word) {
            if (str_contains($text, ArabicTextNormalizer::normalize($word))) {
                return true;
            }
        }

        return false;
    }
}
