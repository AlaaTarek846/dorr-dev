<?php

namespace Modules\Rides\Services\Lifecycle;

use App\Models\Country;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Rides\Enums\BookingMode;
use Modules\Rides\Enums\PricingMode;
use Modules\Rides\Enums\RidePaymentMethod;
use Modules\Rides\Enums\RidePaymentStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RidePriceLine;
use Modules\Rides\Models\RidePriceOffer;
use Modules\Rides\Models\RideQuote;
use Modules\Rides\Models\RideStop;
use Modules\Rides\Services\Bidding\BidPricing;
use Modules\Rides\Services\Circle\FavoriteCircle;
use Modules\Rides\Services\Dispatch\DispatchEngine;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Services\Payment\RideCheckoutPurpose;
use Modules\Rides\Services\Payment\RidePaymentService;
use Modules\Rides\Services\Pricing\PriceExtras;
use Modules\Rides\Services\Pricing\RidePromotions;
use Modules\Rides\Services\Scheduling\ScheduledRideService;
use Modules\User\Models\User;
use Modules\Wallet\Services\CheckoutService;

/**
 * The rider confirms a quote (R-54): the quote's fingerprint is checked again, the switches for the
 * place / category / payment are checked, the rider may have only one active ride, the confirmed
 * numbers are frozen in the ride's snapshot, a wallet ride holds its amount — and the first
 * dispatch round goes out. A card ride waits for the app's payment screen instead (a checkout of
 * purpose `ride`, RideCheckoutPurpose): once paid, the amount is held and the search starts.
 * One Idempotency-Key = one ride.
 *
 * Booked ahead (R-12): with a `scheduled_at` the ride waits as `scheduled` — paid / held now, no
 * search and no rider lock until `scheduled_at − scheduled.lead_time_s` (ScheduledRideService).
 */
class RideRequestService
{
    public function __construct(
        private readonly RideFeatures $features,
        private readonly RideStateMachine $machine,
        private readonly RideNumbers $numbers,
        private readonly RidePaymentService $payments,
        private readonly DispatchEngine $dispatch,
        private readonly RideNotifier $notifier,
        private readonly CheckoutService $checkouts,
        private readonly RidePromotions $promotions,
        private readonly FavoriteCircle $circles,
    ) {}

    /**
     * @param  int|null  $riderPrice  bidding (§9): the price the rider offers — at least the floor
     * @param  Carbon|null  $scheduledAt  booked ahead (R-12)
     * @param  array{name: string, phone: string}|null  $beneficiary  booked for someone else (R-13), the rider having confirmed their consent
     * @param  list<int>|null  $circle  DORR Circle (R-INN-07): offered to these favourite captains first — empty = all of them; null = the usual search
     */
    public function request(User $rider, string $quoteUuid, string $fingerprint, RidePaymentMethod $method, ?string $idempotencyKey = null, ?int $riderPrice = null, ?Carbon $scheduledAt = null, ?int $passengers = null, ?string $pickupNote = null, ?array $beneficiary = null, ?array $circle = null, bool $useWallet = false, string $shareWith = 'any'): Ride
    {
        if ($idempotencyKey !== null && ($existing = Ride::query()->where('user_id', $rider->id)->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            if ($existing->quote()->value('uuid') !== $quoteUuid) {
                throw new RidesException('idempotency_conflict', 409);
            }

            return $existing;
        }

        $quote = RideQuote::query()->where('uuid', $quoteUuid)->where('user_id', $rider->id)->with('category')->first()
            ?? throw new RidesException('quote_not_found', 404);
        if ($quote->isExpired()) {
            throw new RidesException('quote_expired', 409);
        }
        if (! hash_equals($quote->fingerprint, $fingerprint)) {
            throw new RidesException('quote_changed', 409);
        }

        $features = [RideFeatures::categoryFeature($quote->category->slug), $method->value];
        if ($scheduledAt !== null) {
            $features[] = 'scheduled';
        }
        if ($beneficiary !== null) {
            $features[] = 'book_for_other';
        }
        if ($circle !== null) {
            $features[] = 'favorite_circle';
        }
        foreach ($features as $feature) {
            if (! $this->features->allows($quote->country_id, $quote->city_id, $feature)) {
                throw RidesException::featureDisabled($feature);
            }
        }
        if ($method === RidePaymentMethod::Cash && ! $quote->category->flag('cash_allowed', true)) {
            throw RidesException::featureDisabled('cash');
        }
        $pickupNote = $this->pickupNote($quote, $pickupNote);
        $beneficiary = $beneficiary === null ? null : $this->beneficiary($rider, $beneficiary);
        $circleIds = $circle === null ? null : $this->circles->resolve($rider, $circle);
        if ($passengers !== null && $quote->category->capacity_max !== null && $passengers > (int) $quote->category->capacity_max) {
            throw new RidesException('too_many_passengers', 422, ['max' => (int) $quote->category->capacity_max], ['max' => (int) $quote->category->capacity_max]);
        }

        // Bidding: the rider's own price, never under the floor (§9.1); the agreed price comes later.
        $bid = $quote->policy_snapshot['bid'] ?? null;
        if ($scheduledAt !== null) {
            $this->assertCanSchedule($rider, $quote, $scheduledAt, $bid !== null);
        }
        if ($bid !== null) {
            if ($riderPrice === null) {
                throw new RidesException('bid_price_required', 422, [], ['floor_minor' => (int) $bid['floor'], 'suggested_minor' => (int) $bid['suggested']]);
            }
            if ($riderPrice < (int) $bid['floor']) {
                throw new RidesException('bid_below_floor', 422, [], ['floor_minor' => (int) $bid['floor']]);
            }
            if ($riderPrice > (int) $bid['suggested'] * 5) {
                throw new RidesException('bid_too_high', 422);
            }
        }

        $card = $method === RidePaymentMethod::Card;
        $scheduled = $scheduledAt !== null;
        $lead = (int) ($quote->policy_snapshot['policy']['scheduled.lead_time_s'] ?? 1800);
        $ride = DB::transaction(function () use ($rider, $quote, $method, $idempotencyKey, $card, $bid, $riderPrice, $scheduled, $scheduledAt, $lead, $passengers, $pickupNote, $beneficiary, $circleIds, $useWallet, $shareWith) {
            $debt = 0;
            $ride = Ride::query()->create([
                'uuid' => (string) Str::uuid(),
                'number' => $this->numbers->next(),
                'user_id' => $rider->id,
                'category_id' => $quote->category_id,
                'country_id' => $quote->country_id,
                'city_id' => $quote->city_id,
                'currency_id' => $quote->currency_id,
                'status' => match (true) {
                    $card => RideStatus::AwaitingPayment,
                    $scheduled => RideStatus::Scheduled,
                    $bid !== null => RideStatus::Bidding,
                    default => RideStatus::Searching,
                },
                'booking_mode' => $scheduled ? BookingMode::Scheduled : ($bid !== null ? BookingMode::Bid : BookingMode::Instant),
                'scheduled_at' => $scheduledAt,
                'pricing_mode' => $quote->pricing_mode,
                'open_destination' => $quote->destination_lat === null,
                // D-60, D-61: how many ride, and what the captain needs to find them.
                'passengers' => $passengers,
                'pickup_note' => $pickupNote,
                // R-13: who is picked up when it isn't the rider; the rider stays the one who pays.
                'beneficiary' => $beneficiary,
                // R-16..R-18: the approved points the trip starts / ends at, frozen with it.
                // DORR Circle: only these favourite captains are asked, until the rider says otherwise.
                'search_scope' => $circleIds === null ? 'all' : 'circle',
                'circle_driver_ids' => $circleIds,
                'pickup_point' => $quote->pickup_point,
                'dropoff_point' => $quote->dropoff_point,
                'quote_id' => $quote->id,
                'confirmed_snapshot' => [],
                'agreed_price_minor' => $quote->total_minor,
                'payment_method' => $method,
                'share_with' => in_array($shareWith, ['any', 'male', 'female'], true) ? $shareWith : 'any',
                'payment_status' => match ($method) {
                    RidePaymentMethod::Wallet => RidePaymentStatus::Held,
                    RidePaymentMethod::Card => RidePaymentStatus::AwaitingTopup,
                    RidePaymentMethod::Cash => RidePaymentStatus::Pending,
                },
                'origin_lat' => $quote->origin_lat,
                'origin_lng' => $quote->origin_lng,
                'origin_address' => $quote->origin_address,
                'destination_lat' => $quote->destination_lat,
                'destination_lng' => $quote->destination_lng,
                'destination_address' => $quote->destination_address,
                'planned_polyline' => $quote->polyline,
                'planned_distance_m' => $quote->distance_m,
                'planned_duration_s' => $quote->duration_s,
                'requested_at' => now(),
                'search_started_at' => $card || $scheduled ? null : now(),
                // A scheduled ride starts by itself when its time comes near (ScheduledRideService::tick).
                'next_dispatch_at' => match (true) {
                    $card => null,
                    $scheduled => $scheduledAt->copy()->subSeconds($lead),
                    default => now(),
                },
                'idempotency_key' => $idempotencyKey,
            ]);

            // A booking ahead doesn't stop the rider from riding now: it takes the lock when it starts.
            if (! $scheduled) {
                try {
                    RideActiveLock::query()->create(['lock_key' => RideActiveLock::forRider($rider->id), 'ride_id' => $ride->id]);
                } catch (QueryException) {
                    throw new RidesException('ride_in_progress', 409);
                }
            }

            // An old debt is collected with a cash ride (Jawad's rider_debt_paid).
            if ($method === RidePaymentMethod::Cash) {
                $debt = $this->payments->debtOf($ride);
            }

            $lines = $quote->lines;
            if ($debt > 0) {
                array_splice($lines, max(0, count($lines) - 1), 0, [['code' => 'previous_balance', 'amount_minor' => $debt, 'visible_to' => 'both']]);
                $lines = array_map(fn (array $l) => $l['code'] === 'total' ? ['amount_minor' => $l['amount_minor'] + $debt] + $l : $l, $lines);
            }
            // R-48: the chosen way of paying adds its fee (the quote showed each method's); kept for the end.
            $extras = (array) ($quote->policy_snapshot['extras'] ?? []);
            $paymentFee = 0;
            if ($bid === null && ($fee = $this->paymentFeeRule($quote, $method)) !== null) {
                $extras['payment_method_fee'] = $fee;
                $paymentFee = (int) ($quote->policy_snapshot['payment_fees'][$method->value] ?? 0);
                if ($paymentFee > 0 && $quote->distance_m !== null) {
                    $lines = PriceExtras::insert($lines, ['code' => 'payment_method_fee', 'amount_minor' => $paymentFee, 'visible_to' => 'both', 'meta' => $fee]);
                    $lines = array_map(fn (array $l) => $l['code'] === 'total' ? ['amount_minor' => $l['amount_minor'] + $paymentFee] + $l : $l, $lines);
                }
            }
            $sum = fn (string $code) => collect($lines)->where('code', $code)->sum('amount_minor');

            // R-45: the coupon is spent now (checked again); it comes back if the ride never happens.
            if (isset($extras['coupon'])) {
                $this->promotions->redeem($ride, $extras['coupon'], -$sum('discount'));
            }

            $ride->fill([
                'previous_balance_minor' => $debt,
                'agreed_price_minor' => $bid === null && $quote->pricing_mode === PricingMode::Upfront ? (int) $quote->total_minor + $debt + $paymentFee : null,
                'rider_offer_minor' => $bid !== null ? $riderPrice : null,
                'bid_floor_minor' => $bid !== null ? (int) $bid['floor'] : null,
                'bid_suggested_minor' => $bid !== null ? (int) $bid['suggested'] : null,
                'confirmed_snapshot' => [
                    'policy' => $quote->policy_snapshot['policy'] ?? [],
                    'policy_ids' => $quote->policy_snapshot['policy_ids'] ?? [],
                    'tax' => $quote->policy_snapshot['tax'] ?? null,
                    'quote_uuid' => $quote->uuid,
                    'quote_version' => $quote->version,
                    // The airport / pickup-only zone the pickup is in (R-17, D-276): its queue serves this ride first.
                    'zone_id' => $quote->policy_snapshot['zone_id'] ?? null,
                    // O-17: booked by the hour — the hours, the hour price and each minute past them.
                    'hourly' => $quote->policy_snapshot['hourly'] ?? null,
                    // R-233..R-237, R-NEW-06: the add-ons — who may be asked, and what the captain sees.
                    'options' => $quote->policy_snapshot['options'] ?? null,
                    'hold_minor' => $bid !== null ? $riderPrice : (int) $quote->hold_minor + $paymentFee,
                    'route_estimated' => $quote->route_estimated,
                    'estimate' => ['min_minor' => $quote->estimate_min_minor, 'max_minor' => $quote->estimate_max_minor],
                    // Service fee, coupon, payment fee — priced again the same way at the end (PriceExtras).
                    'extras' => $extras === [] ? null : $extras,
                    'breakdown' => $quote->distance_m === null ? null : [
                        'fare' => $sum('fare'),
                        'commission' => $sum('commission'),
                        'tax' => $sum('tax'),
                        'customer_fee' => $sum('customer_fee'),
                        'discount' => -$sum('discount') - $sum('wait_save'),
                        'payment_method_fee' => $sum('payment_method_fee'),
                        'driver_net' => $sum('driver_net'),
                        'total' => $sum('total'),
                    ],
                ],
            ])->save();

            foreach ((array) $quote->stops as $i => $stop) {
                RideStop::query()->create(['ride_id' => $ride->id, 'seq' => $i + 1, 'lat' => $stop['lat'], 'lng' => $stop['lng'], 'address' => $stop['address'] ?? null]);
            }
            foreach ($lines as $i => $line) {
                RidePriceLine::query()->create([
                    'ride_id' => $ride->id, 'phase' => 'confirmed', 'code' => $line['code'], 'amount_minor' => $line['amount_minor'],
                    'visible_to' => $line['visible_to'], 'meta' => $line['meta'] ?? null, 'sort_order' => $i, 'created_at' => now(),
                ]);
            }

            if ($bid !== null) {
                // What the driver would get at the rider's price (shown on the request card).
                $ride->forceFill(['confirmed_snapshot' => ['breakdown' => BidPricing::breakdown($ride, $riderPrice)] + $ride->confirmed_snapshot])->save();
                RidePriceOffer::query()->create(['ride_id' => $ride->id, 'price_minor' => $riderPrice, 'floor_minor' => (int) $bid['floor'], 'reason' => 'initial', 'created_at' => now()]);
            }

            if ($method === RidePaymentMethod::Wallet) {
                $this->payments->hold($ride, $bid !== null ? $riderPrice : (int) $quote->hold_minor + $paymentFee);
            }
            // O-37: cash with the wallet — whatever the wallet has, up to the price, is held now and paid
            // at the end; the rest is cash. An empty wallet simply means all cash.
            if ($useWallet && $method === RidePaymentMethod::Cash) {
                $part = min($this->payments->available($this->payments->riderWallet($ride)), $bid !== null ? (int) $riderPrice : (int) $quote->hold_minor + $paymentFee);
                if ($part > 0) {
                    $this->payments->hold($ride, $part, 'wallet_part');
                }
            }

            $this->machine->record($ride, 'created', 'user', $rider->id, ['lat' => $ride->origin_lat, 'lng' => $ride->origin_lng], array_filter([
                'quote_uuid' => $quote->uuid, 'payment_method' => $method->value, 'scheduled_at' => $scheduledAt?->toIso8601String(),
                'for_someone_else' => $beneficiary !== null,
            ]), null, $ride->status);

            if ($card) {
                $checkout = $this->checkouts->create($rider, Country::query()->findOrFail($quote->country_id), RideCheckoutPurpose::KEY, ['ride_uuid' => $ride->uuid]);
                $ride->forceFill(['confirmed_snapshot' => ['checkout_uuid' => $checkout->uuid] + $ride->confirmed_snapshot])->save();
            }

            return $ride;
        });

        // R-233: Wait & Save — the search starts after the wait the rider took for the lower price.
        if (! $scheduled && $bid === null && ($delay = (int) ($ride->confirmed_snapshot['extras']['wait_save']['delay_s'] ?? 0)) > 0 && ! $card) {
            $ride->forceFill(['next_dispatch_at' => now()->addSeconds($delay)])->save();
        }
        $this->notifier->changed($ride, 'created');
        if ($scheduled && ! $card) {
            $this->notifier->rider($ride, 'scheduled', ['time' => ScheduledRideService::localTime($ride)]);
        }
        if (! $card && ! $scheduled && $ride->next_dispatch_at?->lte(now())) {
            $this->dispatch->runRound($ride);
        }

        return $ride->refresh();
    }

    /**
     * D-61: a short note about the pickup ("the east gate") — what the captain needs, nothing more.
     * No phone numbers or links: the two talk through the app's chat and calls only.
     */
    private function pickupNote(RideQuote $quote, ?string $note): ?string
    {
        $note = trim(preg_replace('/\s+/u', ' ', (string) $note) ?? '');
        if ($note === '') {
            return null;
        }
        $max = (int) ($quote->policy_snapshot['policy']['booking.note_max_chars'] ?? 120);
        if (mb_strlen($note) > $max) {
            throw new RidesException('pickup_note_too_long', 422, ['max' => $max], ['max' => $max]);
        }
        // Eastern-Arabic digits count too; seven digits in a row (spaces / dashes between them allowed) look like a phone.
        $digits = strtr($note, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        if (preg_match('/\d(?:[\s\-.]*\d){6,}/u', $digits) || preg_match('~(https?://|www\.|@\w)~iu', $note)) {
            throw new RidesException('pickup_note_contact', 422);
        }

        return $note;
    }

    /**
     * R-13: the person the ride is for — a tidied name and a digits-only number that isn't the rider's
     * own, with when the rider confirmed they agreed to be contacted (the consent evidence).
     *
     * @param  array{name: string, phone: string}  $beneficiary
     * @return array{name: string, phone: string, consented_at: string}
     */
    private function beneficiary(User $rider, array $beneficiary): array
    {
        $phone = (string) preg_replace('/[^\d+]/', '', $beneficiary['phone']);
        if ($phone === (string) $rider->phone) {
            throw new RidesException('beneficiary_is_you', 422);
        }

        return [
            'name' => trim((string) preg_replace('/\s+/u', ' ', $beneficiary['name'])),
            'phone' => $phone,
            'consented_at' => now()->toIso8601String(),
        ];
    }

    /**
     * R-48: the policy's fee for this way of paying, or null when it adds nothing (the wallet never does).
     *
     * @return array{method: string, minor: int, bp: int}|null
     */
    private function paymentFeeRule(RideQuote $quote, RidePaymentMethod $method): ?array
    {
        $policy = (array) ($quote->policy_snapshot['policy'] ?? []);
        [$minor, $bp] = match ($method) {
            RidePaymentMethod::Card => [(int) ($policy['payment.card_fee_minor'] ?? 0), (int) ($policy['payment.card_fee_bp'] ?? 0)],
            RidePaymentMethod::Cash => [(int) ($policy['payment.cash_fee_minor'] ?? 0), 0],
            RidePaymentMethod::Wallet => [0, 0],
        };

        return $minor + $bp > 0 ? ['method' => $method->value, 'minor' => $minor, 'bp' => $bp] : null;
    }

    /**
     * A booking ahead (R-12): switched on there, not bidding (offers are live), between
     * `scheduled.min_ahead_s` and `max_ahead_s` from now, within the rider's limit, and not on top of
     * another of their bookings.
     */
    private function assertCanSchedule(User $rider, RideQuote $quote, Carbon $at, bool $bidding): void
    {
        $policy = (array) ($quote->policy_snapshot['policy'] ?? []);
        if ($bidding) {
            throw new RidesException('scheduled_not_for_bidding', 422);
        }
        $ahead = now()->diffInSeconds($at, false);
        $min = (int) ($policy['scheduled.min_ahead_s'] ?? 3600);
        $max = (int) ($policy['scheduled.max_ahead_s'] ?? 604800);
        if ($ahead < $min) {
            throw new RidesException('scheduled_too_soon', 422, ['minutes' => intdiv($min, 60)], ['min_ahead_s' => $min]);
        }
        if ($ahead > $max) {
            throw new RidesException('scheduled_too_far', 422, ['days' => max(1, intdiv($max, 86400))], ['max_ahead_s' => $max]);
        }

        $upcoming = Ride::query()->where('user_id', $rider->id)->where('status', RideStatus::Scheduled->value)->get();
        $limit = (int) ($policy['scheduled.max_per_rider'] ?? 5);
        if ($upcoming->count() >= $limit) {
            throw new RidesException('scheduled_limit', 422, ['max' => $limit]);
        }
        $gap = (int) ($policy['scheduled.overlap_gap_s'] ?? 1800);
        foreach ($upcoming as $other) {
            $apart = abs($other->scheduled_at->diffInSeconds($at, false));
            if ($apart < max((int) $other->planned_duration_s, (int) $quote->duration_s) + $gap) {
                throw new RidesException('scheduled_overlap', 422, ['time' => ScheduledRideService::localTime($other)]);
            }
        }
    }
}
