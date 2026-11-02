<?php

namespace Modules\Freelance\Services\Consultations;

use App\Models\Country;
use App\Services\Notifications\NotificationCenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Chat\Models\ChatCalendarItem;
use Modules\Chat\Services\CalendarService;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceConsultation as Consultation;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\FreelanceChatService;
use Modules\Freelance\Services\Moderation\Sanctions;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Offers\OfferChecks;
use Modules\Freelance\Services\Policy\FreelanceLedger;
use Modules\Freelance\Services\Projects\FreelanceEarnings;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletHold;
use Modules\Wallet\Services\WalletService;
use Throwable;

/**
 * Paid consultations (FL-L07), from booking to the money.
 *
 * - **Booking** is a *request* for a free time of the freelancer: it holds the price and client fee in the client's wallet at once (no wallet
 *   money, no request) but does not take the time. The freelancer **accepts** it (checked again under a lock on the freelancer: the time is
 *   theirs from then on, so no other request can get it, and the other pending requests for those hours are declined and given back) or
 *   **declines** it; no answer by `response_hours` (or the start) gives everything back. Accepting puts it in the client's calendar. The
 *   session itself is a video call (FL-L16): until it comes, the time is what the two sides keep.
 * - **Cancelling** before the start: by the freelancer gives everything back; by the client gives everything back up to
 *   `free_cancel_hours` before the start, and later pays the freelancer as if it took place.
 * - **After the session** the client has `complaint_hours` to say something went wrong (the staff then decide: pay or give back) or to
 *   say it went well (paid at once); saying nothing pays the freelancer when that time is over.
 *
 * Paying means: the client's holds are captured, the freelancer is credited the net (through the earnings, with their waiting days) and
 * the client fee and the commission are booked as income. Every wallet movement has a key of its own, so a repeat never pays twice.
 * The rules are the country's when it was booked (kept on the row).
 */
class Consultations
{
    public const REFERENCE = 'freelance_consultation';

    /** Both sides are reminded this long before it starts. */
    public const REMIND_MINUTES = 30;

    /** @var list<string> the statuses that hold a time of the freelancer */
    public const TAKING = [Consultation::BOOKED, Consultation::DISPUTED];

    public function __construct(
        private readonly ConsultationOffers $offers,
        private readonly WalletService $wallets,
        private readonly FreelanceLedger $ledger,
        private readonly FreelanceNotifier $notifier,
    ) {}

    // -------------------------------------------------------------- booking

    /** @param  array{offer: string, minutes: int, starts_at: string, topic: string, request_id?: ?string}  $data */
    public function book(User $client, array $data): Consultation
    {
        app(Sanctions::class)->assertAllowed($client, 'work');
        $requestId = isset($data['request_id']) ? (string) $data['request_id'] : null;
        if ($requestId !== null && ($same = $this->sameTap($client, $requestId)) !== null) {
            return $same;
        }
        $offer = $this->offers->bookable($client, (string) $data['offer']);
        $f = $offer->freelancer;
        $minutes = (int) $data['minutes'];
        $price = $offer->priceFor($minutes) ?? throw new FreelanceException('consultation_length_unknown', 422);
        $start = CarbonImmutable::parse((string) $data['starts_at'])->utc()->startOfMinute();
        $topic = trim(strip_tags((string) $data['topic']));
        // No way to reach each other outside Dorr (FL-308).
        app(OfferChecks::class)->assertNoContact($topic);

        try {
            $c = DB::transaction(function () use ($client, $offer, $f, $minutes, $price, $start, $topic, $requestId) {
                // One booking at a time for this freelancer: the time is checked and taken together.
                Freelancer::query()->whereKey($f->id)->lockForUpdate()->first();
                if (! $this->offers->isFree($offer, $minutes, $start)) {
                    throw new FreelanceException('consultation_slot_taken', 409);
                }
                $cost = $this->offers->cost($f, $price);
                $earn = $this->offers->earn($f, $price);
                $rules = $this->offers->rules((int) $f->country_id);
                $country = Country::query()->with('currency')->findOrFail((int) $f->country_id);
                $c = Consultation::query()->create([
                    'freelancer_id' => $f->id, 'offer_id' => $offer->id, 'client_id' => $client->id, 'country_id' => $country->id, 'request_id' => $requestId,
                    'minutes' => $minutes, 'starts_at' => $start, 'ends_at' => $start->addMinutes($minutes), 'topic' => $topic,
                    'title' => $offer->title, 'price_minor' => $cost['price_minor'], 'client_fee_minor' => $cost['client_fee_minor'],
                    'commission_minor' => $earn['commission_minor'], 'net_minor' => $earn['net_minor'], 'currency_code' => (string) $country->currency?->code,
                    'policy' => $rules, 'status' => Consultation::PENDING,
                    // Answered before the start at the latest.
                    'respond_by' => min(now()->addHours($rules['response_hours']), $start),
                ]);
                $this->hold($c, $client, $country);

                return $c;
            });
        } catch (QueryException $e) {
            // The same tap twice at the same moment: the first one won.
            if ($requestId !== null && ($same = $this->sameTap($client, $requestId)) !== null) {
                return $same;
            }
            throw $e;
        }

        $this->tell($c, 'freelancer', 'consultation.requested', ['minutes' => $c->minutes]);

        return $c->load(['freelancer.media', 'client']);
    }

    // -------------------------------------------------------------- the freelancer answers

    /** The freelancer takes the time: it is theirs and the client's now; the other pending requests for those hours go back. */
    public function accept(Consultation $consultation, Freelancer $f): Consultation
    {
        $taken = collect();
        $c = DB::transaction(function () use ($consultation, $f, &$taken) {
            // The same lock as booking: one answer at a time for this freelancer.
            Freelancer::query()->whereKey($f->id)->lockForUpdate()->first();
            $c = $this->locked($consultation);
            if ($c->status !== Consultation::PENDING || ! $c->starts_at->isFuture()) {
                throw new FreelanceException('consultation_invalid_state', 409);
            }
            $start = CarbonImmutable::instance($c->starts_at);
            $end = CarbonImmutable::instance($c->ends_at);
            if ($this->offers->overlapping($f, $start, $end, self::TAKING, $c->id)->exists()) {
                throw new FreelanceException('consultation_slot_taken', 409);
            }
            $c->fill(['status' => Consultation::BOOKED, 'accepted_at' => now(), 'respond_by' => null])->save();
            $taken = $this->offers->overlapping($f, $start, $end, [Consultation::PENDING], $c->id)->lockForUpdate()->with(['freelancer', 'client'])->get();
            foreach ($taken as $other) {
                $other->fill(['status' => Consultation::DECLINED, 'cancelled_by' => 'system', 'cancel_reason' => 'taken', 'cancelled_at' => now(), 'respond_by' => null]);
                $this->refund($other, 'freelance_consultation_declined');
                $other->save();
            }

            return $c;
        });

        $this->addToCalendar($c, $c->client);
        $this->tell($c, 'client', 'consultation.accepted');
        foreach ($taken as $other) {
            $this->tell($other, 'client', 'consultation.declined');
        }

        return $this->fresh($c);
    }

    /** The freelancer says no: everything goes back to the client. */
    public function decline(Consultation $consultation, Freelancer $f, ?string $reason): Consultation
    {
        $reason = $reason === null ? null : (trim(strip_tags($reason)) ?: null);
        $c = DB::transaction(function () use ($consultation, $reason) {
            $c = $this->locked($consultation);
            if ($c->status !== Consultation::PENDING) {
                throw new FreelanceException('consultation_invalid_state', 409);
            }
            $c->fill(['status' => Consultation::DECLINED, 'cancelled_by' => 'freelancer', 'cancel_reason' => $reason, 'cancelled_at' => now(), 'respond_by' => null]);
            $this->refund($c, 'freelance_consultation_declined');
            $c->save();

            return $c;
        });
        $this->tell($c, 'client', 'consultation.declined');

        return $this->fresh($c);
    }

    // -------------------------------------------------------------- the two sides

    /** Before the start, by either side. @param  User|Freelancer  $by */
    public function cancel(Consultation $consultation, Model $by, ?string $reason): Consultation
    {
        $side = FreelanceChatService::side($by);
        // A freelancer "cancelling" a request is declining it.
        if ($side === 'freelancer' && $consultation->status === Consultation::PENDING) {
            return $this->decline($consultation, $by, $reason);
        }
        $reason = $reason === null ? null : (trim(strip_tags($reason)) ?: null);
        $c = DB::transaction(function () use ($consultation, $side, $reason) {
            $c = $this->locked($consultation);
            if (! in_array($c->status, [Consultation::BOOKED, Consultation::PENDING], true) || ! $c->starts_at->isFuture()) {
                throw new FreelanceException('consultation_invalid_state', 409);
            }
            // Withdrawing a request that is not accepted yet is always free.
            $late = $side === 'client' && $c->status === Consultation::BOOKED && now()->gt($c->freeCancelUntil());
            $c->fill(['status' => Consultation::CANCELLED, 'cancelled_by' => $side, 'cancel_reason' => $reason, 'late_cancel' => $late, 'cancelled_at' => now()]);
            if ($late) {
                // Too close to the start: the freelancer kept the time, so they are paid.
                $this->payOut($c, 'late_cancel');
            } else {
                $this->refund($c, 'freelance_consultation_cancelled');
            }
            $c->save();

            return $c;
        });

        $this->dropCalendar($c);
        $this->tell($c, $side === 'client' ? 'freelancer' : 'client', 'consultation.cancelled');
        if ($c->late_cancel) {
            $this->tell($c, 'freelancer', 'consultation.paid', ['amount' => $this->money($c->net_minor, $c)]);
        }

        return $this->fresh($c);
    }

    /** The client: it went well — the freelancer is paid now. */
    public function confirm(Consultation $consultation, User $client): Consultation
    {
        $c = DB::transaction(function () use ($consultation) {
            $c = $this->locked($consultation);
            if ($c->status !== Consultation::BOOKED || $c->starts_at->isFuture()) {
                throw new FreelanceException('consultation_invalid_state', 409);
            }
            $c->fill(['status' => Consultation::COMPLETED, 'confirmed_at' => now()]);
            $this->payOut($c, 'confirmed');
            $c->save();

            return $c;
        });
        $this->tell($c, 'freelancer', 'consultation.paid', ['amount' => $this->money($c->net_minor, $c)]);

        return $this->fresh($c);
    }

    /** The client: something went wrong (the freelancer didn't come, or it wasn't what was booked) — the staff decide. */
    public function complain(Consultation $consultation, User $client, string $text): Consultation
    {
        $text = trim(strip_tags($text));
        $c = DB::transaction(function () use ($consultation, $text) {
            $c = $this->locked($consultation);
            if ($c->status !== Consultation::BOOKED || $c->starts_at->isFuture() || now()->gte($c->releaseAt())) {
                throw new FreelanceException('consultation_invalid_state', 409);
            }
            $c->fill(['status' => Consultation::DISPUTED, 'complaint' => $text, 'complained_at' => now()])->save();

            return $c;
        });
        $this->tell($c, 'freelancer', 'consultation.complained');
        $admins = app(NotificationCenter::class)->adminsWith('freelance-consultations.update');
        if ($admins->isNotEmpty()) {
            app(NotificationCenter::class)->send($admins, 'freelance.consultation.complained', 'freelance_admin_consultation_complaint_title', 'freelance_admin_consultation_complaint_body',
                ['name' => (string) $c->client?->name], ['type' => 'freelance_consultation_complaint', 'consultation' => $c->uuid], push: false);
        }

        return $this->fresh($c);
    }

    /** The staff decide a complaint: `pay` the freelancer or `refund` the client. */
    public function resolve(Consultation $consultation, string $decision, ?string $note, int $adminId): Consultation
    {
        $c = DB::transaction(function () use ($consultation, $decision, $note, $adminId) {
            $c = $this->locked($consultation);
            if ($c->status !== Consultation::DISPUTED) {
                throw new FreelanceException('consultation_invalid_state', 409);
            }
            $c->fill(['resolution' => $decision, 'resolution_note' => $note, 'resolved_by' => $adminId, 'resolved_at' => now()]);
            if ($decision === 'pay') {
                $c->status = Consultation::COMPLETED;
                $this->payOut($c, 'dispute');
            } else {
                $c->status = Consultation::REFUNDED;
                $this->refund($c, 'freelance_consultation_refunded');
            }
            $c->save();

            return $c;
        });
        if ($decision === 'pay') {
            $this->tell($c, 'freelancer', 'consultation.paid', ['amount' => $this->money($c->net_minor, $c)]);
            $this->tell($c, 'client', 'consultation.complaint_closed');
        } else {
            $this->tell($c, 'client', 'consultation.refunded', ['amount' => $this->money($c->totalMinor(), $c)]);
            $this->tell($c, 'freelancer', 'consultation.refunded');
        }

        return $this->fresh($c);
    }

    // -------------------------------------------------------------- the clock

    /** Every few minutes: reminds both before the start, asks the client how it went at the end, and pays what nobody complained about. */
    public function sweep(): array
    {
        $done = ['expired' => 0, 'reminded' => 0, 'asked' => 0, 'paid' => 0];
        Consultation::query()->where('status', Consultation::PENDING)->where(fn ($q) => $q->where('respond_by', '<=', now())->orWhere('starts_at', '<=', now()))->pluck('id')
            ->each(function (int $id) use (&$done): void {
                $c = DB::transaction(function () use ($id) {
                    $c = Consultation::query()->whereKey($id)->lockForUpdate()->with(['freelancer', 'client'])->first();
                    if ($c === null || $c->status !== Consultation::PENDING) {
                        return null;
                    }
                    $c->fill(['status' => Consultation::DECLINED, 'cancelled_by' => 'system', 'cancel_reason' => 'expired', 'cancelled_at' => now(), 'respond_by' => null]);
                    $this->refund($c, 'freelance_consultation_declined');
                    $c->save();

                    return $c;
                });
                if ($c !== null) {
                    $this->tell($c, 'client', 'consultation.declined');
                    $this->tell($c, 'freelancer', 'consultation.expired');
                    $done['expired']++;
                }
            });

        Consultation::query()->where('status', Consultation::BOOKED)->whereNull('reminded_at')
            ->whereBetween('starts_at', [now(), now()->addMinutes(self::REMIND_MINUTES)])->get()
            ->each(function (Consultation $c) use (&$done): void {
                $c->forceFill(['reminded_at' => now()])->save();
                $this->tell($c, 'client', 'consultation.reminder');
                $this->tell($c, 'freelancer', 'consultation.reminder');
                $done['reminded']++;
            });

        Consultation::query()->where('status', Consultation::BOOKED)->whereNull('ended_told_at')->where('ends_at', '<=', now())->get()
            ->each(function (Consultation $c) use (&$done): void {
                $c->forceFill(['ended_told_at' => now()])->save();
                $this->tell($c, 'client', 'consultation.review_due', ['hours' => (int) ($c->policy['complaint_hours'] ?? 24)]);
                $done['asked']++;
            });

        Consultation::query()->where('status', Consultation::BOOKED)->where('ends_at', '<=', now()->subHour())->pluck('id')
            ->each(function (int $id) use (&$done): void {
                try {
                    $c = DB::transaction(function () use ($id) {
                        $c = Consultation::query()->whereKey($id)->lockForUpdate()->first();
                        if ($c === null || $c->status !== Consultation::BOOKED || $c->releaseAt()->isFuture()) {
                            return null;
                        }
                        $c->status = Consultation::COMPLETED;
                        $this->payOut($c, 'auto');
                        $c->save();

                        return $c;
                    });
                } catch (FreelanceException $e) {
                    Log::warning('[Freelance] consultation pay-out skipped', ['consultation' => $id, 'error' => $e->apiErrorCode()]);

                    return;
                }
                if ($c !== null) {
                    $this->tell($c, 'freelancer', 'consultation.paid', ['amount' => $this->money($c->net_minor, $c)]);
                    $done['paid']++;
                }
            });

        return $done;
    }

    // -------------------------------------------------------------- lists

    public function forClient(User $client, string $uuid): Consultation
    {
        return Consultation::query()->where('client_id', $client->id)->where('uuid', $uuid)->with(['freelancer.media', 'client'])->firstOrFail();
    }

    public function forFreelancer(Freelancer $f, string $uuid): Consultation
    {
        return Consultation::query()->where('freelancer_id', $f->id)->where('uuid', $uuid)->with(['freelancer.media', 'client'])->firstOrFail();
    }

    /** @param  'client'|'freelancer'  $side  @param  'upcoming'|'past'|'cancelled'|null  $tab */
    public function page(string $side, int $personId, ?string $tab, int $perPage): LengthAwarePaginator
    {
        $q = Consultation::query()->where($side === 'client' ? 'client_id' : 'freelancer_id', $personId)->with(['freelancer.media', 'client']);
        $this->tab($q, $tab);

        return ($tab === 'upcoming' ? $q->orderBy('starts_at') : $q->orderByDesc('starts_at'))->orderByDesc('id')->paginate($perPage);
    }

    /** @return array<string, int> how many in each tab */
    public function counts(string $side, int $personId): array
    {
        $out = [];
        foreach (['upcoming', 'past', 'cancelled'] as $tab) {
            $q = Consultation::query()->where($side === 'client' ? 'client_id' : 'freelancer_id', $personId);
            $this->tab($q, $tab);
            $out[$tab] = $q->count();
        }

        return $out;
    }

    public function adminPage(?string $status, ?string $search, int $perPage): LengthAwarePaginator
    {
        return Consultation::query()->with(['freelancer', 'client'])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->whereHas('client', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term))
                    ->orWhereHas('freelancer', fn ($f) => $f->where('name', 'like', $term)->orWhere('phone', 'like', $term))
                    ->orWhere('topic', 'like', $term));
            })
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Consultation::DISPUTED])->orderByDesc('starts_at')->paginate($perPage);
    }

    /** @return array<string, int> */
    public function adminCounts(): array
    {
        $counts = Consultation::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return collect(Consultation::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();
    }

    /**
     * @param  'client'|'freelancer'|'staff'  $side  each sees the money from their side
     * @return array<string, mixed>
     */
    public function present(Consultation $c, string $side): array
    {
        $phase = $c->phase();
        $decimals = (int) (Country::query()->with('currency')->find($c->country_id)?->currency?->decimal_places ?? 2);
        $started = ! $c->starts_at->isFuture();
        $booked = $c->status === Consultation::BOOKED;
        $pending = $c->status === Consultation::PENDING && ! $started;

        return [
            'uuid' => $c->uuid,
            'offer' => $c->offer?->uuid,
            'status' => $c->status,
            'phase' => $phase,
            'title' => $c->label(),
            'minutes' => $c->minutes,
            'starts_at' => $c->starts_at->toIso8601String(),
            'ends_at' => $c->ends_at->toIso8601String(),
            'topic' => $c->topic,
            'currency' => $c->currency_code,
            'decimals' => $decimals,
            'money' => match ($side) {
                'client' => ['price_minor' => $c->price_minor, 'client_fee_minor' => $c->client_fee_minor, 'total_minor' => $c->totalMinor()],
                'freelancer' => ['price_minor' => $c->price_minor, 'commission_minor' => $c->commission_minor, 'net_minor' => $c->net_minor],
                default => ['price_minor' => $c->price_minor, 'client_fee_minor' => $c->client_fee_minor, 'total_minor' => $c->totalMinor(), 'commission_minor' => $c->commission_minor, 'net_minor' => $c->net_minor],
            },
            'freelancer' => $side === 'freelancer' ? null : [
                'uuid' => $c->freelancer?->uuid, 'name' => $c->freelancer?->name, 'headline' => $c->freelancer?->headline,
                'avatar_url' => $c->freelancer?->getFirstMediaUrl('avatar') ?: null,
            ] + ($side === 'staff' ? ['id' => $c->freelancer_id, 'phone' => $c->freelancer?->phone] : []),
            'client' => $side === 'client' ? null : ['name' => $c->client?->name] + ($side === 'staff' ? ['id' => $c->client_id, 'phone' => $c->client?->phone] : []),
            'respond_by' => $c->respond_by?->toIso8601String(),
            'accepted_at' => $c->accepted_at?->toIso8601String(),
            'rules' => [
                'free_cancel_until' => $c->freeCancelUntil()->toIso8601String(),
                'release_at' => $c->releaseAt()->toIso8601String(),
                'free_cancel_hours' => (int) ($c->policy['free_cancel_hours'] ?? 24),
                'complaint_hours' => (int) ($c->policy['complaint_hours'] ?? 24),
                'response_hours' => (int) ($c->policy['response_hours'] ?? 24),
            ],
            'cancel' => ! in_array($c->status, [Consultation::CANCELLED, Consultation::DECLINED], true) ? null : [
                'by' => $c->cancelled_by, 'reason' => $c->cancel_reason, 'late' => $c->late_cancel, 'at' => $c->cancelled_at?->toIso8601String(),
            ],
            'complaint' => $c->complained_at === null ? null : ['text' => $c->complaint, 'at' => $c->complained_at->toIso8601String()],
            'resolution' => $c->resolved_at === null ? null : ['decision' => $c->resolution, 'note' => $c->resolution_note, 'at' => $c->resolved_at->toIso8601String()],
            'confirmed_at' => $c->confirmed_at?->toIso8601String(),
            'settled_at' => $c->settled_at?->toIso8601String(),
            'refunded_at' => $c->refunded_at?->toIso8601String(),
            // The client's stars once it is over: they may rate it, and the freelancer reads it.
            'review' => app(ConsultationReviews::class)->present($c, $side),
            'can' => match ($side) {
                'client' => [
                    'cancel' => ($booked && ! $started) || $pending,
                    // Cancelling now gives everything back.
                    'cancel_free' => $pending || ($booked && ! $started && now()->lte($c->freeCancelUntil())),
                    'confirm' => $booked && $started,
                    'complain' => $booked && $started && now()->lt($c->releaseAt()),
                ],
                'freelancer' => ['cancel' => $booked && ! $started, 'accept' => $pending, 'decline' => $pending],
                default => ['resolve' => $c->status === Consultation::DISPUTED],
            },
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------- money

    private function hold(Consultation $c, User $client, Country $country): void
    {
        $wallet = $this->wallets->firstOrCreateWallet($client, $country)->fresh(['currency']);
        if (strtoupper((string) $wallet->currency?->code) !== strtoupper($c->currency_code)) {
            throw new FreelanceException('wallet_currency', 422, ['currency' => $c->currency_code], ['currency' => $c->currency_code]);
        }
        $needed = $c->totalMinor();
        $available = $wallet->spendableMinor();
        if ($available < $needed) {
            throw new FreelanceException('wallet_insufficient', 422, [
                'needed' => $this->money($needed, $c), 'available' => $this->money($available, $c),
            ], ['needed_minor' => $needed, 'available_minor' => $available, 'shortfall_minor' => $needed - $available, 'currency' => $c->currency_code]);
        }
        // Rewards, then spend-only, then withdrawable (Wallet::SPEND_ORDER).
        foreach ($wallet->spendParts($needed) as [$bucket, $part]) {
            if ($part <= 0) {
                continue;
            }
            $this->wallets->hold($wallet, $part, $bucket, [
                'reference_type' => self::REFERENCE, 'reference_id' => $c->id, 'reason_code' => 'freelance_consultation',
                // Held until it is paid out or given back — never freed by the clock.
                'expires_at' => null,
                'idempotency_key' => "freelance:consultation:{$c->uuid}:{$bucket->value}",
            ]);
            $wallet->refresh();
        }
    }

    /** @return Collection<int, WalletHold> spend-only first */
    private function holds(Consultation $c): Collection
    {
        return WalletHold::query()->where('reference_type', self::REFERENCE)->where('reference_id', $c->id)->where('status', WalletHoldStatus::Active)->get()
            ->sortBy(fn (WalletHold $h) => $h->bucket === WalletBucket::SpendOnly ? 0 : 1)->values();
    }

    /** Inside the transaction: the client's holds are captured, the freelancer credited the net, DORR's part booked. */
    private function payOut(Consultation $c, string $why): void
    {
        $holds = $this->holds($c);
        if ((int) $holds->sum('amount_minor') !== $c->totalMinor()) {
            throw new FreelanceException('consultation_not_held', 409);
        }
        $country = Country::query()->with('currency')->findOrFail($c->country_id);
        $label = $c->label();
        $capture = null;
        foreach ($holds as $hold) {
            $capture = $this->wallets->capture($hold, (int) $hold->amount_minor, WalletTransactionType::ServicePayment, [
                'reference_type' => self::REFERENCE, 'reference_id' => $c->id,
                'notes' => ['key' => 'wallet.notes.freelance_consultation_payment', 'variables' => ['name' => (string) $c->freelancer?->name]],
                'idempotency_key' => "freelance:consultation:{$c->uuid}:pay:{$hold->id}",
            ]);
        }
        $earning = app(FreelanceEarnings::class)->creditConsultation($c, $country, $c->net_minor, $why === 'dispute' ? 'dispute' : 'consultation', "freelance:consultation:{$c->uuid}:earning");
        $earningTx = $earning?->wallet_transaction_id === null ? null : \Modules\Wallet\Models\WalletTransaction::query()->find($earning->wallet_transaction_id);
        $this->ledger->clientFee($c->client_fee_minor, $country->currency, $country, $c, $label, $capture);
        $this->ledger->commission($c->commission_minor, $country->currency, $country, $c, $label, $earningTx);
        $c->settled_at = now();
    }

    private function refund(Consultation $c, string $reason): void
    {
        foreach ($this->holds($c) as $hold) {
            $this->wallets->release($hold, $reason);
        }
        $c->refunded_at = now();
    }

    // -------------------------------------------------------------- calendar, words

    private function addToCalendar(Consultation $c, User $client): void
    {
        $calendar = app(CalendarService::class);
        if (! $calendar->enabled()) {
            return;
        }
        try {
            $result = $calendar->create($client, [
                'title' => __('freelance.consultation_calendar_title', ['name' => (string) $c->freelancer?->name]),
                'category' => 'meeting',
                'note' => mb_substr($c->topic, 0, 500),
                'starts_at' => $c->starts_at->toIso8601String(),
                'ends_at' => $c->ends_at->toIso8601String(),
                'timezone' => 'UTC',
            ]);
            $c->forceFill(['calendar_item_id' => $result['item']->id])->save();
        } catch (Throwable $e) {
            // The booking stands without it.
            Log::warning('[Freelance] consultation calendar item failed: '.$e->getMessage());
        }
    }

    private function dropCalendar(Consultation $c): void
    {
        if ($c->calendar_item_id !== null) {
            ChatCalendarItem::query()->whereKey($c->calendar_item_id)->delete();
            $c->forceFill(['calendar_item_id' => null])->save();
        }
    }

    /** @param  array<string, scalar>  $extra */
    private function tell(Consultation $c, string $audience, string $event, array $extra = []): void
    {
        $c->loadMissing(['client', 'freelancer', 'offer']);
        $receiver = $audience === 'client' ? $c->client : $c->freelancer;
        if ($receiver === null) {
            return;
        }
        // The receiver's own phone clock when known (the apps send it), else the listing's.
        $zone = (string) (data_get($receiver, 'timezone') ?: ($c->offer?->timezone ?: config('app.timezone')));
        if (! in_array($zone, timezone_identifiers_list(), true)) {
            $zone = 'UTC';
        }
        $peer = $audience === 'client' ? $c->freelancer : $c->client;
        $this->notifier->notify($receiver, $event, [
            'name' => (string) $peer?->name,
            'time' => $c->starts_at->copy()->setTimezone($zone)->format('Y-m-d H:i'),
        ] + $extra, ['consultation' => $c->uuid]);
    }

    // -------------------------------------------------------------- inside

    private function locked(Consultation $c): Consultation
    {
        return Consultation::query()->whereKey($c->id)->lockForUpdate()->with(['freelancer', 'client'])->firstOrFail();
    }

    private function fresh(Consultation $c): Consultation
    {
        return $c->refresh()->load(['freelancer.media', 'client']);
    }

    private function sameTap(User $client, string $requestId): ?Consultation
    {
        return Consultation::query()->where('client_id', $client->id)->where('request_id', $requestId)->with(['freelancer.media', 'client'])->first();
    }

    private function tab(Builder $q, ?string $tab): void
    {
        match ($tab) {
            'upcoming' => $q->whereIn('status', [Consultation::BOOKED, Consultation::PENDING])->where('ends_at', '>', now()),
            'past' => $q->where(fn ($w) => $w->whereIn('status', [Consultation::COMPLETED, Consultation::DISPUTED, Consultation::REFUNDED])
                ->orWhere(fn ($b) => $b->where('status', Consultation::BOOKED)->where('ends_at', '<=', now()))),
            'cancelled' => $q->whereIn('status', [Consultation::CANCELLED, Consultation::DECLINED]),
            default => null,
        };
    }

    private function money(int $minor, Consultation $c): string
    {
        return number_format($minor / 100, 2).' '.$c->currency_code;
    }
}
