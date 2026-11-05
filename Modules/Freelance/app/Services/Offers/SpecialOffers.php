<?php

namespace Modules\Freelance\Services\Offers;

use App\Models\Country;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Enums\FreelanceProjectStatus as ProjectStatus;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceConsultation as Consultation;
use Modules\Freelance\Models\FreelanceConsultationOffer;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceOfferEvent;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceService as Service;
use Modules\Freelance\Models\FreelanceSpecialOffer as SpecialOffer;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Account\FreelancerProfileLists;
use Modules\Freelance\Services\Chat\OfferChat;
use Modules\Freelance\Services\Consultations\ConsultationOffers;
use Modules\Freelance\Services\Consultations\Consultations;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Market\ServiceOrders;
use Modules\Freelance\Services\Moderation\Sanctions;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Policy\FreelanceFees;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\Agreements;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\ProjectStateMachine;
use Modules\User\Models\User;

/**
 * Special offers to past clients (FL-L09, part A). A freelancer offers a client they already finished work with (a completed agreement or
 * consultation):
 *
 * - **custom** — new work: a title, what it covers, a price, the days and the free revisions;
 * - **service** — one level of one of their ready-made services at a price below its usual one;
 * - **consultation** — one of their consultations with a discount on every length.
 *
 * The offer is the freelancer's yes, so the client's acceptance starts it at once: custom and service work become a private project whose
 * agreement is active (the client then funds the part as in any project); a consultation is booked and confirmed for the time the client
 * picks. The freelancer pays any discount (it is their price); commission and client fee are worked out on the price offered, as anywhere.
 *
 * Kept from being a nuisance: only past clients, a daily cap per freelancer, a cap of open offers per client, a pause after a client says no,
 * and the client can stop a freelancer's special offers altogether. The text carries no way to reach each other outside Dorr. A
 * `request_id` makes a double tap one offer. Every number is policy (`special_offers.*`).
 */
class SpecialOffers
{
    public function __construct(
        private readonly FreelancePolicies $policies,
        private readonly FreelanceFeatures $features,
        private readonly FreelanceNotifier $notifier,
    ) {}

    /** A price with a discount in basis points, to the nearest minor unit (never below one). */
    public static function discounted(int $priceMinor, int $discountBp): int
    {
        return max(1, (int) round($priceMinor * (10000 - $discountBp) / 10000));
    }

    /** @return array{daily_limit: int, open_per_client: int, cooldown_days: int, max_valid_days: int, default_valid_days: int, min_discount_bp: int, max_discount_bp: int, remind_hours: int} */
    public function rules(int $countryId): array
    {
        $p = $this->policies->for($countryId, null);
        $max = max(1, (int) ($p->get('special_offers.max_valid_days') ?? 14));

        return [
            'daily_limit' => max(1, (int) ($p->get('special_offers.daily_limit') ?? 10)),
            'open_per_client' => max(1, (int) ($p->get('special_offers.open_per_client') ?? 1)),
            'cooldown_days' => max(0, (int) ($p->get('special_offers.cooldown_days') ?? 7)),
            'max_valid_days' => $max,
            'default_valid_days' => min($max, 7),
            'min_discount_bp' => 500,
            'max_discount_bp' => 9000,
            'remind_hours' => 24,
        ];
    }

    // ================================================================= the freelancer

    /**
     * The clients this freelancer finished work with, the latest first, each with what they did together and whether an offer can go now.
     *
     * @return list<array<string, mixed>>
     */
    public function pastClients(Freelancer $f, ?string $search = null): array
    {
        $rules = $this->rules((int) $f->country_id);
        $history = $this->history($f);
        if ($history->isEmpty()) {
            return [];
        }
        $muted = DB::table('freelance_special_offer_mutes')->where('freelancer_id', $f->id)->pluck('client_id')->map(fn ($id) => (int) $id)->all();
        $ids = $history->keys()->reject(fn ($id) => in_array($id, $muted, true))->all();
        $term = $search === null ? '' : trim($search);
        $users = User::query()->whereIn('id', $ids)->with('media')
            ->when($term !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))->get()->keyBy('id');
        $open = SpecialOffer::query()->where('freelancer_id', $f->id)->where('status', SpecialOffer::SENT)->where('expires_at', '>', now())
            ->whereIn('client_id', $ids)->get()->groupBy('client_id');
        $declined = SpecialOffer::query()->where('freelancer_id', $f->id)->where('status', SpecialOffer::DECLINED)->whereIn('client_id', $ids)
            ->selectRaw('client_id, max(answered_at) as at')->groupBy('client_id')->pluck('at', 'client_id');

        return $history->filter(fn ($h, $id) => $users->has($id))->map(function (array $h, int $id) use ($f, $users, $open, $declined, $rules) {
            $u = $users[$id];
            $mine = $open->get($id, collect());
            $pause = isset($declined[$id]) && $rules['cooldown_days'] > 0 ? Carbon::parse($declined[$id])->addDays($rules['cooldown_days']) : null;
            $reason = match (true) {
                $mine->count() >= $rules['open_per_client'] => 'has_open',
                $pause !== null && $pause->isFuture() => 'cooldown',
                default => null,
            };

            return [
                'key' => $this->clientKey($f, $id),
                'name' => (string) $u->name,
                'avatar_url' => $u->getSingleMediaUrl('avatar') ?: null,
                'works' => $h['works'],
                'projects' => $h['projects'],
                'consultations' => $h['consultations'],
                'last_title' => $h['last_title'],
                'last_at' => $h['last_at'],
                'open_offer' => $mine->first()?->uuid,
                'can_send' => $reason === null,
                'blocked_reason' => $reason,
                'blocked_until' => $reason === 'cooldown' ? $pause?->toIso8601String() : null,
            ];
        })->sortByDesc('last_at')->values()->all();
    }

    /** What the compose screen offers: my services and consultations open now, my specialties, the rules and what is left today. @return array<string, mixed> */
    public function compose(Freelancer $f): array
    {
        $country = Country::query()->with('currency')->find((int) $f->country_id);
        $rules = $this->rules((int) $f->country_id);
        $services = Service::query()->where('freelancer_id', $f->id)->where('status', Service::PUBLISHED)->with('tiers')->latest('id')->get()
            ->reject(fn (Service $s) => $s->monthly())
            ->map(fn (Service $s) => [
                'uuid' => $s->uuid, 'title' => $s->title,
                'levels' => $s->tiers->sortBy(fn ($t) => array_search($t->level, Service::LEVELS, true))->map(fn ($t) => [
                    'level' => $t->level, 'label' => __('freelance.service_levels.'.$t->level), 'name' => $t->name, 'price_minor' => (int) $t->price_minor,
                    'delivery_days' => (int) $t->delivery_days, 'revisions' => (int) $t->revisions, 'features' => array_values((array) $t->features),
                ])->values()->all(),
            ])->values()->all();
        $offers = app(ConsultationOffers::class);
        $consultations = FreelanceConsultationOffer::query()->where('freelancer_id', $f->id)->where('status', FreelanceConsultationOffer::PUBLISHED)->latest('id')->get()
            ->filter(fn ($o) => $o->setRelation('freelancer', $f) && $offers->open($o))
            ->map(fn ($o) => ['uuid' => $o->uuid, 'title' => $o->title, 'lengths' => $o->lengths()])->values()->all();
        $sentToday = SpecialOffer::query()->where('freelancer_id', $f->id)->where('created_at', '>=', now()->startOfDay())->count();

        return [
            'currency' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code(),
            'decimals' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->decimals(),
            'kinds' => [
                SpecialOffer::CUSTOM => $this->features->allows((int) $f->country_id, 'projects'),
                SpecialOffer::SERVICE => $this->features->allows((int) $f->country_id, 'services') && $services !== [],
                SpecialOffer::CONSULTATION => $this->features->allows((int) $f->country_id, 'consultations') && $consultations !== [],
            ],
            'services' => $services,
            'consultations' => $consultations,
            'specialties' => app(FreelancerProfileLists::class)->specialties($f),
            'rules' => $rules,
            'min_price_minor' => $this->minPrice((int) $f->country_id),
            'today_left' => max(0, $rules['daily_limit'] - $sentToday),
            // The list screen's header reads these too (the apps' envelope carries no extra keys).
            'stats' => $this->stats($f),
            'counts' => $this->counts('freelancer', (int) $f->id),
        ];
    }

    /** What a price leaves the freelancer, and what the client pays on top of it. @return array<string, mixed> */
    public function quote(Freelancer $f, int $priceMinor, ?int $specialtyId = null): array
    {
        $project = $this->pseudoProject($f, $specialtyId);
        $earn = app(FreelancerOffers::class)->preview($project, $priceMinor, $f);
        $cost = app(ClientOfferViews::class)->cost($project, $priceMinor);

        return [
            'price_minor' => $earn['price_minor'], 'commission_minor' => $earn['commission_minor'], 'net_minor' => $earn['net_minor'],
            'client_fee_minor' => $cost['client_fee_minor'], 'client_total_minor' => $cost['total_minor'], 'currency' => $project->currency_code, 'lines' => $earn['lines'],
        ];
    }

    /**
     * @param  array{client: string, kind: string, title?: ?string, message?: ?string, description?: ?string, specialty_id?: ?int, service?: ?string,
     *     level?: ?string, consultation?: ?string, price_minor?: ?int, discount_bp?: ?int, delivery_days?: ?int, revisions?: ?int, valid_days?: ?int, request_id?: ?string}  $data
     */
    public function send(Freelancer $f, array $data): SpecialOffer
    {
        $requestId = isset($data['request_id']) ? (string) $data['request_id'] : null;
        if ($requestId !== null && ($same = SpecialOffer::query()->where('freelancer_id', $f->id)->where('request_id', $requestId)->first()) !== null) {
            return $same;
        }
        $this->assertCanWork($f);
        $countryId = (int) $f->country_id;
        $rules = $this->rules($countryId);
        $client = $this->clientByKey($f, (string) $data['client']);
        $message = $this->clean($data['message'] ?? null);
        // No way to reach each other outside Dorr (FL-308).
        app(OfferChecks::class)->assertNoContact($message);
        $validDays = (int) ($data['valid_days'] ?? $rules['default_valid_days']);
        if ($validDays < 1 || $validDays > $rules['max_valid_days']) {
            throw new FreelanceException('special_offer_valid_days', 422, ['max' => $rules['max_valid_days']], ['max' => $rules['max_valid_days']]);
        }
        $terms = match ((string) $data['kind']) {
            SpecialOffer::CUSTOM => $this->customTerms($f, $data),
            SpecialOffer::SERVICE => $this->serviceTerms($f, $data),
            SpecialOffer::CONSULTATION => $this->consultationTerms($f, $data, $rules),
            default => throw new FreelanceException('special_offer_kind_unknown', 422),
        };
        $country = Country::query()->with('currency')->findOrFail($countryId);

        try {
            $offer = DB::transaction(function () use ($f, $client, $rules, $data, $terms, $message, $validDays, $requestId, $country) {
                // One sending at a time for this freelancer: the caps are read and taken together.
                Freelancer::query()->whereKey($f->id)->lockForUpdate()->first();
                $this->assertWithinCaps($f, $client, $rules);

                return SpecialOffer::query()->create($terms + [
                    'freelancer_id' => $f->id, 'client_id' => $client->id, 'country_id' => $country->id, 'kind' => (string) $data['kind'],
                    'message' => $message, 'currency_code' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code(), 'status' => SpecialOffer::SENT,
                    'expires_at' => now()->addDays($validDays), 'request_id' => $requestId,
                ]);
            });
        } catch (QueryException $e) {
            // The same tap twice at the same moment: the first one won.
            if ($requestId !== null && ($same = SpecialOffer::query()->where('freelancer_id', $f->id)->where('request_id', $requestId)->first()) !== null) {
                return $same;
            }
            throw $e;
        }

        $this->notifier->notify($client, 'special_offer.received', ['name' => (string) $f->name, 'title' => (string) $offer->title], ['special_offer' => $offer->uuid]);

        return $offer;
    }

    /** The freelancer takes back an offer the client did not answer. */
    public function withdraw(SpecialOffer $offer, Freelancer $f): SpecialOffer
    {
        return DB::transaction(function () use ($offer) {
            $o = $this->locked($offer);
            $this->assertOpen($o);
            $o->fill(['status' => SpecialOffer::WITHDRAWN, 'answered_at' => now()])->save();

            return $o;
        });
    }

    public function forFreelancer(Freelancer $f, string $uuid): SpecialOffer
    {
        return SpecialOffer::query()->where('freelancer_id', $f->id)->where('uuid', $uuid)->with(['client.media', 'service', 'consultationOffer', 'specialty.translations'])->firstOrFail();
    }

    // ================================================================= the client

    /** Opening it tells the freelancer it was seen (once). */
    public function forClient(User $client, string $uuid, bool $markSeen = true): SpecialOffer
    {
        $o = SpecialOffer::query()->where('client_id', $client->id)->where('uuid', $uuid)->with(['freelancer.media', 'service', 'consultationOffer', 'specialty.translations'])->firstOrFail();
        if ($markSeen && $o->viewed_at === null) {
            $o->forceFill(['viewed_at' => now()])->save();
        }

        return $o;
    }

    /**
     * The client takes it. Custom work and a service start at once (a private project with its agreement active); a consultation needs
     * the time picked (`minutes`, `starts_at`, `topic`) and is confirmed on the spot.
     *
     * @param  array{request_id?: ?string, minutes?: ?int, starts_at?: ?string, topic?: ?string}  $data
     */
    public function accept(SpecialOffer $offer, User $client, array $data = []): SpecialOffer
    {
        app(Sanctions::class)->assertAllowed($client, 'work');
        if ($offer->status === SpecialOffer::ACCEPTED) {
            // A second tap on an offer already taken: what it made.
            return $offer->refresh();
        }
        $this->assertOpen($offer);
        $f = $offer->freelancer;
        if ($f === null || $f->trashed() || $f->status !== FreelancerStatus::Verified || app(Sanctions::class)->restrictions($f)['work'] !== null) {
            throw new FreelanceException('special_offer_unavailable', 409);
        }

        if ($offer->kind === SpecialOffer::CONSULTATION) {
            $this->needFeature($offer, 'consultations');
            $this->needFeature($offer, 'wallet_payment');
            $c = app(Consultations::class)->book($client, [
                'offer' => (string) $offer->consultationOffer?->uuid, 'minutes' => (int) ($data['minutes'] ?? 0), 'starts_at' => (string) ($data['starts_at'] ?? ''),
                'topic' => (string) ($data['topic'] ?? ''), 'request_id' => $data['request_id'] ?? null, 'recording_consent' => (bool) ($data['recording_consent'] ?? false),
            ], $offer);
            $this->tellAccepted($offer->refresh(), $client);

            return $offer;
        }

        $this->needFeature($offer, 'projects');
        if ($offer->kind === SpecialOffer::SERVICE) {
            $this->needFeature($offer, 'services');
        } else {
            app(ClientProjects::class)->roomFor($client);
        }
        $done = DB::transaction(function () use ($offer, $client, $f) {
            $o = $this->claim($offer, $client);
            $made = $o->kind === SpecialOffer::SERVICE ? $this->startService($o, $client, $f) : $this->startCustom($o, $client, $f);
            $this->accepted($o, $made);

            return $o;
        });
        $this->tellAccepted($done, $client);

        return $done->refresh();
    }

    /** The client says no (with a reason if they like) and may stop this freelancer's special offers at once. */
    public function decline(SpecialOffer $offer, User $client, ?string $reason, bool $mute = false): SpecialOffer
    {
        $reason = $this->clean($reason, 300);
        $o = DB::transaction(function () use ($offer, $reason) {
            $o = $this->locked($offer);
            $this->assertOpen($o);
            $o->fill(['status' => SpecialOffer::DECLINED, 'answered_at' => now(), 'decline_reason' => $reason])->save();

            return $o;
        });
        if ($mute) {
            $this->mute($client, $o->freelancer, true);
        }
        $this->notifier->notify($o->freelancer, 'special_offer.declined', ['name' => (string) $client->name, 'title' => (string) $o->title], ['special_offer' => $o->uuid]);

        return $o;
    }

    /** Stop (or allow again) a freelancer's special offers; stopping also ends the ones waiting. */
    public function mute(User $client, Freelancer $f, bool $on): void
    {
        if (! $on) {
            DB::table('freelance_special_offer_mutes')->where('client_id', $client->id)->where('freelancer_id', $f->id)->delete();

            return;
        }
        DB::table('freelance_special_offer_mutes')->insertOrIgnore(['client_id' => $client->id, 'freelancer_id' => $f->id, 'created_at' => now()]);
        SpecialOffer::query()->where('client_id', $client->id)->where('freelancer_id', $f->id)->where('status', SpecialOffer::SENT)
            ->update(['status' => SpecialOffer::DECLINED, 'answered_at' => now(), 'updated_at' => now()]);
    }

    public function muted(User $client, int $freelancerId): bool
    {
        return DB::table('freelance_special_offer_mutes')->where('client_id', $client->id)->where('freelancer_id', $freelancerId)->exists();
    }

    /** How many offers wait for this client (the badge on the freelance home). */
    public function waiting(User $client): int
    {
        return SpecialOffer::query()->where('client_id', $client->id)->where('status', SpecialOffer::SENT)->where('expires_at', '>', now())->count();
    }

    // ================================================================= lists

    /** @param  'client'|'freelancer'  $side */
    public function page(string $side, int $personId, ?string $tab, int $perPage): LengthAwarePaginator
    {
        $q = SpecialOffer::query()->where($side === 'client' ? 'client_id' : 'freelancer_id', $personId)
            ->with(['freelancer.media', 'client.media', 'service', 'consultationOffer', 'specialty.translations']);
        match ($tab) {
            'open' => $q->where('status', SpecialOffer::SENT)->where('expires_at', '>', now()),
            'accepted' => $q->where('status', SpecialOffer::ACCEPTED),
            'past' => $q->where(fn ($w) => $w->whereIn('status', SpecialOffer::TABS['past'])->orWhere(fn ($x) => $x->where('status', SpecialOffer::SENT)->where('expires_at', '<=', now()))),
            default => null,
        };

        return $q->latest('id')->paginate($perPage);
    }

    /** @param  'client'|'freelancer'  $side  @return array<string, int> */
    public function counts(string $side, int $personId): array
    {
        $base = fn () => SpecialOffer::query()->where($side === 'client' ? 'client_id' : 'freelancer_id', $personId);

        return [
            'open' => $base()->where('status', SpecialOffer::SENT)->where('expires_at', '>', now())->count(),
            'accepted' => $base()->where('status', SpecialOffer::ACCEPTED)->count(),
            'past' => $base()->where(fn ($w) => $w->whereIn('status', SpecialOffer::TABS['past'])->orWhere(fn ($x) => $x->where('status', SpecialOffer::SENT)->where('expires_at', '<=', now())))->count(),
        ];
    }

    /** @return array<string, int> what the freelancer's offers did: sent, seen, accepted, and the share accepted of those answered */
    public function stats(Freelancer $f): array
    {
        $rows = SpecialOffer::query()->where('freelancer_id', $f->id);
        $sent = (clone $rows)->count();
        $accepted = (clone $rows)->where('status', SpecialOffer::ACCEPTED)->count();
        $answered = (clone $rows)->whereIn('status', [SpecialOffer::ACCEPTED, SpecialOffer::DECLINED])->count();

        return ['sent' => $sent, 'seen' => (clone $rows)->whereNotNull('viewed_at')->count(), 'accepted' => $accepted, 'accept_rate' => $answered === 0 ? 0 : (int) round($accepted * 100 / $answered)];
    }

    // ================================================================= the clock

    /** Hourly: open offers past their time end, and a client is told once when one ends within a day. @return array{expired: int, reminded: int} */
    public function sweep(): array
    {
        $expired = SpecialOffer::query()->where('status', SpecialOffer::SENT)->where('expires_at', '<=', now())
            ->update(['status' => SpecialOffer::EXPIRED, 'updated_at' => now()]);
        $reminded = 0;
        SpecialOffer::query()->where('status', SpecialOffer::SENT)->whereNull('reminded_at')->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addHours(24))->where('created_at', '<=', now()->subHours(24))
            ->with(['client', 'freelancer'])->each(function (SpecialOffer $o) use (&$reminded): void {
                if (SpecialOffer::query()->whereKey($o->id)->whereNull('reminded_at')->update(['reminded_at' => now()]) === 1 && $o->client !== null) {
                    $this->notifier->notify($o->client, 'special_offer.ending', ['name' => (string) $o->freelancer?->name, 'title' => (string) $o->title], ['special_offer' => $o->uuid]);
                    $reminded++;
                }
            });

        return ['expired' => $expired, 'reminded' => $reminded];
    }

    // ================================================================= what the apps read

    /**
     * @param  'client'|'freelancer'|'staff'  $side  each sees the money from their side
     * @return array<string, mixed>
     */
    public function present(SpecialOffer $o, string $side): array
    {
        $snap = (array) $o->snapshot;
        $status = $o->shownStatus();
        $decimals = (int) ($snap['decimals'] ?? 2);
        $money = null;
        if ($o->price_minor !== null) {
            $q = $this->quote($o->freelancer ?? new Freelancer(['country_id' => $o->country_id]), (int) $o->price_minor, $o->freelance_specialty_id);
            $money = match ($side) {
                'client' => ['price_minor' => $q['price_minor'], 'client_fee_minor' => $q['client_fee_minor'], 'total_minor' => $q['client_total_minor'],
                    'lines' => FreelanceFees::lines('client', ['price_minor' => $q['price_minor'], 'client_fee_minor' => $q['client_fee_minor'], 'total_minor' => $q['client_total_minor']])],
                'freelancer' => ['price_minor' => $q['price_minor'], 'commission_minor' => $q['commission_minor'], 'net_minor' => $q['net_minor'], 'lines' => $q['lines']],
                default => $q,
            };
        }
        $f = $o->freelancer;
        $u = $o->client;

        return [
            'uuid' => $o->uuid,
            'kind' => $o->kind,
            'kind_label' => __('freelance.special_offer_kinds.'.$o->kind),
            'status' => $status,
            'status_label' => __('freelance.special_offer_statuses.'.$status),
            'title' => $o->title,
            'message' => $o->message,
            'description' => $o->description,
            'specialty' => $o->specialty === null ? null : ['id' => $o->specialty->id, 'name' => $o->specialty->translatedName()],
            'service' => $o->kind !== SpecialOffer::SERVICE ? null : [
                'uuid' => $o->service?->uuid, 'title' => $snap['service_title'] ?? $o->service?->title, 'level' => $o->service_level,
                'level_label' => __('freelance.service_levels.'.$o->service_level), 'tier_name' => $snap['tier']['name'] ?? null,
                'features' => array_values((array) ($snap['tier']['features'] ?? [])),
            ],
            'consultation' => $o->kind !== SpecialOffer::CONSULTATION ? null : [
                'uuid' => $o->consultationOffer?->uuid, 'title' => $snap['consultation_title'] ?? $o->consultationOffer?->title,
                'lengths' => $this->lengths($o),
            ],
            'price_minor' => $o->price_minor,
            'list_price_minor' => $o->list_price_minor,
            'saving_minor' => $o->list_price_minor !== null && $o->price_minor !== null ? max(0, $o->list_price_minor - $o->price_minor) : null,
            'discount_bp' => $o->discount_bp ?? ($o->list_price_minor ? (int) round(($o->list_price_minor - (int) $o->price_minor) * 10000 / $o->list_price_minor) : null),
            'delivery_days' => $o->delivery_days,
            'revisions' => $o->revisions,
            'currency' => $o->currency_code,
            'decimals' => $decimals,
            'money' => $money,
            'expires_at' => $o->expires_at->toIso8601String(),
            'sent_at' => $o->created_at?->toIso8601String(),
            'viewed_at' => $side === 'client' ? null : $o->viewed_at?->toIso8601String(),
            'answered_at' => $o->answered_at?->toIso8601String(),
            'decline_reason' => $o->decline_reason,
            'freelancer' => $side === 'freelancer' ? null : [
                'uuid' => $f?->uuid, 'name' => $f?->name, 'headline' => $f?->headline, 'avatar_url' => $f?->getFirstMediaUrl('avatar') ?: null,
                'verified' => $f?->status === FreelancerStatus::Verified,
            ] + ($side === 'staff' ? ['id' => $o->freelancer_id] : []),
            'client' => $side === 'client' ? null : [
                'key' => $f === null ? null : $this->clientKey($f, (int) $o->client_id), 'name' => $u?->name, 'avatar_url' => $u?->getSingleMediaUrl('avatar') ?: null,
            ] + ($side === 'staff' ? ['id' => $o->client_id, 'phone' => $u?->phone] : []),
            // What accepting it made, for the app to open.
            'result' => $status !== SpecialOffer::ACCEPTED ? null : [
                'project' => $o->project?->uuid, 'service_order' => $o->serviceOrder?->uuid, 'consultation' => $o->consultation?->uuid,
            ],
            'muted' => $side === 'client' && $u !== null ? $this->muted($u, (int) $o->freelancer_id) : null,
            'can' => match ($side) {
                'client' => ['accept' => $status === SpecialOffer::SENT, 'decline' => $status === SpecialOffer::SENT],
                'freelancer' => ['withdraw' => $status === SpecialOffer::SENT],
                default => new \stdClass,
            },
        ];
    }

    // ================================================================= inside

    /** Inside a transaction: the offer locked, still open — the one way it is taken. */
    public function claim(SpecialOffer $offer, User $client): SpecialOffer
    {
        $o = $this->locked($offer);
        if ((int) $o->client_id !== (int) $client->id) {
            throw new FreelanceException('special_offer_unavailable', 409);
        }
        $this->assertOpen($o);

        return $o;
    }

    /** @param  array{project_id?: int, service_order_id?: int, consultation_id?: int}  $made */
    public function accepted(SpecialOffer $offer, array $made): void
    {
        SpecialOffer::query()->whereKey($offer->id)->update($made + ['status' => SpecialOffer::ACCEPTED, 'answered_at' => now(), 'updated_at' => now()]);
    }

    private function tellAccepted(SpecialOffer $o, User $client): void
    {
        $this->notifier->notify($o->freelancer, 'special_offer.accepted', ['name' => (string) $client->name, 'title' => (string) $o->title],
            ['special_offer' => $o->uuid, 'project' => $o->project?->uuid, 'consultation' => $o->consultation?->uuid]);
    }

    /** Custom work: a private project with the freelancer's offer accepted and the agreement both approved — active, waiting for the money. @return array{project_id: int} */
    private function startCustom(SpecialOffer $o, User $client, Freelancer $f): array
    {
        $project = Project::query()->create([
            'client_id' => $client->id, 'country_id' => $o->country_id, 'freelance_specialty_id' => $o->freelance_specialty_id,
            'title' => $o->title, 'description' => $o->description, 'budget_type' => 'fixed', 'budget_min_minor' => $o->price_minor,
            'currency_code' => $o->currency_code, 'duration_days' => $o->delivery_days, 'visibility' => 'private', 'status' => ProjectStatus::Awarded,
            'awarded_at' => now(), 'offers_closed_at' => now(), 'offers_close_reason' => 'chosen',
        ]);
        app(ProjectStateMachine::class)->record($project, 'project.special_offer', 'client', $client->id, ['special_offer' => $o->uuid], null, ProjectStatus::Awarded);
        $this->startProject($project, $o, $client, $f, (int) $o->revisions, null);

        return ['project_id' => $project->id];
    }

    /** One level of a service at the offered price: the same order as FL-L04, already accepted by the freelancer. @return array{project_id: int, service_order_id: int} */
    private function startService(SpecialOffer $o, User $client, Freelancer $f): array
    {
        /** @var Service $s */
        $s = Service::query()->whereKey($o->service_id)->lockForUpdate()->with(['freelancer', 'country.currency'])->first();
        if ($s === null || ! $s->live()) {
            throw new FreelanceException('special_offer_unavailable', 409);
        }
        $snap = (array) $o->snapshot;
        $order = app(ServiceOrders::class)->book($s, $client, [
            'title' => (string) ($snap['service_title'] ?? $s->title), 'level' => (string) $o->service_level,
            'tier' => ['name' => (string) ($snap['tier']['name'] ?? ''), 'description' => $snap['tier']['description'] ?? null, 'features' => array_values((array) ($snap['tier']['features'] ?? []))],
            'addons' => [], 'decimals' => (int) ($snap['decimals'] ?? 2), 'price_minor' => (int) $o->price_minor,
            'delivery_days' => (int) $o->delivery_days, 'revisions' => (int) $o->revisions,
        ], null, null, now()->addHours(48));
        $agreement = $order->agreement()->firstOrFail();
        if ($this->features->allows((int) $o->country_id, 'chat')) {
            rescue(fn () => app(OfferChat::class)->open($order->project->offers()->first(), $client), report: false);
        }
        app(Agreements::class)->approve($agreement, $f);

        return ['project_id' => $order->project_id, 'service_order_id' => $order->id];
    }

    /** The project's accepted offer and its agreement, sent by the client (their acceptance) and approved by the freelancer (their offer). */
    private function startProject(Project $project, SpecialOffer $o, User $client, Freelancer $f, int $revisions, ?string $includes): void
    {
        $preview = app(FreelancerOffers::class)->preview($project, (int) $o->price_minor, $f);
        $offer = Offer::query()->create([
            'project_id' => $project->id, 'freelancer_id' => $f->id, 'price_minor' => $preview['price_minor'], 'currency_code' => $o->currency_code,
            'duration_days' => $o->delivery_days, 'details' => trim(($o->message ?? '')."\n\n".($o->description ?? '')), 'status' => OfferStatus::Accepted,
            'accepted_at' => now(), 'commission_minor' => $preview['commission_minor'], 'net_minor' => $preview['net_minor'], 'fees' => $preview['commission'],
            // A special offer costs the freelancer none of their offers.
            'free' => false,
        ]);
        FreelanceOfferEvent::query()->create([
            'offer_id' => $offer->id, 'type' => 'offer.accepted', 'from_status' => null, 'to_status' => OfferStatus::Accepted->value,
            'actor_type' => 'client', 'actor_id' => $client->id, 'payload' => ['auto' => 'special_offer', 'special_offer' => $o->uuid],
        ]);
        $agreements = app(Agreements::class);
        $agreement = $agreements->draft($project, $offer);
        $agreement->forceFill([
            'scope' => (string) ($o->description ?? $o->title),
            'policy' => ['review.free_revisions' => $revisions] + (array) $agreement->policy,
            'includes' => $includes,
        ])->save();
        $agreements->send($agreement, $client, now()->addDays(Agreements::ANSWER_DAYS), false);
        if ($this->features->allows((int) $o->country_id, 'chat')) {
            rescue(fn () => app(OfferChat::class)->open($offer, $client), report: false);
        }
        $agreements->approve($agreement, $f);
    }

    /** @return array<string, mixed> */
    private function customTerms(Freelancer $f, array $data): array
    {
        $title = $this->clean($data['title'] ?? null, 120);
        $description = $this->clean($data['description'] ?? null, 5000);
        if ($title === null || mb_strlen($title) < 5) {
            throw new FreelanceException('special_offer_title_short', 422);
        }
        if ($description === null || mb_strlen($description) < 30) {
            throw new FreelanceException('special_offer_description_short', 422, ['min' => 30]);
        }
        app(OfferChecks::class)->assertNoContact($title);
        app(OfferChecks::class)->assertNoContact($description);
        $price = (int) ($data['price_minor'] ?? 0);
        $min = $this->minPrice((int) $f->country_id);
        if ($price < max(1, (int) $min)) {
            throw new FreelanceException('special_offer_price_low', 422, ['min' => $min ?? 1], ['min_minor' => $min]);
        }
        $days = (int) ($data['delivery_days'] ?? 0);
        if ($days < 1 || $days > (int) config('freelance.projects.max_duration_days', 365)) {
            throw new FreelanceException('special_offer_days_invalid', 422);
        }
        $revisions = (int) ($data['revisions'] ?? 0);
        if ($revisions < 0 || $revisions > 20) {
            throw new FreelanceException('special_offer_revisions_invalid', 422);
        }
        $specialtyId = isset($data['specialty_id']) ? (int) $data['specialty_id'] : null;
        if ($specialtyId !== null && ! DB::table('freelancer_specialties')->where('freelancer_id', $f->id)->where('freelance_specialty_id', $specialtyId)->exists()) {
            throw new FreelanceException('special_offer_specialty_unknown', 422);
        }
        $specialtyId ??= DB::table('freelancer_specialties')->where('freelancer_id', $f->id)->orderByDesc('is_primary')->value('freelance_specialty_id');

        return [
            'title' => $title, 'description' => $description, 'price_minor' => $price, 'delivery_days' => $days, 'revisions' => $revisions,
            'freelance_specialty_id' => $specialtyId === null ? null : (int) $specialtyId, 'snapshot' => ['decimals' => $this->decimals($f)],
        ];
    }

    /** @return array<string, mixed> */
    private function serviceTerms(Freelancer $f, array $data): array
    {
        if (! $this->features->allows((int) $f->country_id, 'services')) {
            throw new FreelanceException('feature_off', 403);
        }
        $s = Service::query()->where('freelancer_id', $f->id)->where('uuid', (string) ($data['service'] ?? ''))->with('tiers')->first();
        if ($s === null || ! $s->live() || $s->monthly()) {
            throw new FreelanceException('special_offer_service_unavailable', 422);
        }
        $tier = $s->tiers->firstWhere('level', (string) ($data['level'] ?? '')) ?? throw new FreelanceException('service_level_unknown', 422);
        $price = (int) ($data['price_minor'] ?? 0);
        if ($price < 1 || $price >= (int) $tier->price_minor) {
            throw new FreelanceException('special_offer_price_not_lower', 422, [], ['list_price_minor' => (int) $tier->price_minor]);
        }

        return [
            'service_id' => $s->id, 'service_level' => $tier->level, 'freelance_specialty_id' => $s->freelance_specialty_id,
            'title' => $s->title, 'price_minor' => $price, 'list_price_minor' => (int) $tier->price_minor,
            'delivery_days' => (int) $tier->delivery_days, 'revisions' => (int) $tier->revisions,
            'snapshot' => [
                'decimals' => $this->decimals($f), 'service_title' => $s->title,
                'tier' => ['name' => $tier->name, 'description' => $tier->description, 'features' => array_values((array) $tier->features)],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function consultationTerms(Freelancer $f, array $data, array $rules): array
    {
        if (! $this->features->allows((int) $f->country_id, 'consultations')) {
            throw new FreelanceException('feature_off', 403);
        }
        $c = FreelanceConsultationOffer::query()->where('freelancer_id', $f->id)->where('uuid', (string) ($data['consultation'] ?? ''))->first();
        if ($c === null || ! app(ConsultationOffers::class)->open($c->setRelation('freelancer', $f))) {
            throw new FreelanceException('special_offer_consultation_unavailable', 422);
        }
        $bp = (int) ($data['discount_bp'] ?? 0);
        if ($bp < $rules['min_discount_bp'] || $bp > $rules['max_discount_bp']) {
            throw new FreelanceException('special_offer_discount_invalid', 422, ['min' => $rules['min_discount_bp'] / 100, 'max' => $rules['max_discount_bp'] / 100]);
        }

        return [
            'consultation_offer_id' => $c->id, 'title' => $c->title, 'discount_bp' => $bp,
            'snapshot' => ['decimals' => $this->decimals($f), 'consultation_title' => $c->title, 'lengths' => $c->lengths()],
        ];
    }

    /** @return list<array{minutes: int, list_price_minor: int, price_minor: int, client_fee_minor: int, total_minor: int}> every length at the offered price */
    private function lengths(SpecialOffer $o): array
    {
        $lengths = $o->consultationOffer?->lengths() ?: (array) (((array) $o->snapshot)['lengths'] ?? []);
        $f = $o->freelancer;

        return collect($lengths)->map(function ($l) use ($o, $f) {
            $price = self::discounted((int) $l['price_minor'], (int) $o->discount_bp);
            $cost = $f === null ? ['client_fee_minor' => 0, 'total_minor' => $price] : app(ConsultationOffers::class)->cost($f, $price);

            return ['minutes' => (int) $l['minutes'], 'list_price_minor' => (int) $l['price_minor'], 'price_minor' => $price, 'client_fee_minor' => $cost['client_fee_minor'], 'total_minor' => $cost['total_minor']];
        })->values()->all();
    }

    /** @param  array<string, int>  $rules */
    private function assertWithinCaps(Freelancer $f, User $client, array $rules): void
    {
        if (SpecialOffer::query()->where('freelancer_id', $f->id)->where('created_at', '>=', now()->startOfDay())->count() >= $rules['daily_limit']) {
            throw new FreelanceException('special_offer_daily_limit', 429, ['max' => $rules['daily_limit']], ['max' => $rules['daily_limit']]);
        }
        $open = SpecialOffer::query()->where('freelancer_id', $f->id)->where('client_id', $client->id)->where('status', SpecialOffer::SENT)->where('expires_at', '>', now())->count();
        if ($open >= $rules['open_per_client']) {
            throw new FreelanceException('special_offer_client_has_open', 409);
        }
        if ($rules['cooldown_days'] > 0) {
            $last = SpecialOffer::query()->where('freelancer_id', $f->id)->where('client_id', $client->id)->where('status', SpecialOffer::DECLINED)->max('answered_at');
            $until = $last === null ? null : Carbon::parse($last)->addDays($rules['cooldown_days']);
            if ($until !== null && $until->isFuture()) {
                throw new FreelanceException('special_offer_cooldown', 409, ['date' => $until->translatedFormat('j M')], ['until' => $until->toIso8601String()]);
            }
        }
    }

    private function assertCanWork(Freelancer $f): void
    {
        if ($f->status !== FreelancerStatus::Verified) {
            throw new FreelanceException('special_offer_unverified', 403);
        }
        app(Sanctions::class)->assertAllowed($f, 'work');
    }

    private function assertOpen(SpecialOffer $o): void
    {
        if (! $o->open()) {
            $status = $o->shownStatus();

            throw new FreelanceException('special_offer_not_open', 409, ['status' => __('freelance.special_offer_statuses.'.$status)], ['status' => $status]);
        }
    }

    private function needFeature(SpecialOffer $o, string $feature): void
    {
        if (! $this->features->allows((int) $o->country_id, $feature)) {
            throw new FreelanceException('feature_off', 403);
        }
    }

    /**
     * client_id => what the two finished together.
     *
     * @return Collection<int, array{works: int, projects: int, consultations: int, last_title: ?string, last_at: ?string}>
     */
    private function history(Freelancer $f): Collection
    {
        $works = collect();
        Agreement::query()->where('freelancer_id', $f->id)->where('status', Agreement::COMPLETED)->with('project:id,title')->latest('completed_at')
            ->get(['id', 'client_id', 'project_id', 'completed_at'])->each(function ($a) use ($works) {
                $works->push(['client' => (int) $a->client_id, 'kind' => 'project', 'title' => $a->project?->title, 'at' => $a->completed_at]);
            });
        Consultation::query()->where('freelancer_id', $f->id)->where('status', Consultation::COMPLETED)->latest('ends_at')
            ->get(['id', 'client_id', 'title', 'ends_at'])->each(function ($c) use ($works) {
                $works->push(['client' => (int) $c->client_id, 'kind' => 'consultation', 'title' => $c->title, 'at' => $c->ends_at]);
            });

        return $works->groupBy('client')->map(function (Collection $rows) {
            $last = $rows->sortByDesc(fn ($r) => $r['at']?->getTimestamp() ?? 0)->first();

            return [
                'works' => $rows->count(), 'projects' => $rows->where('kind', 'project')->count(), 'consultations' => $rows->where('kind', 'consultation')->count(),
                'last_title' => $last['title'], 'last_at' => $last['at']?->toIso8601String(),
            ];
        });
    }

    /** A client's handle in the freelancer's API: stable, but neither their id nor guessable. */
    private function clientKey(Freelancer $f, int $clientId): string
    {
        return substr(hash_hmac('sha256', $f->id.':'.$clientId, (string) config('app.key')), 0, 32);
    }

    private function clientByKey(Freelancer $f, string $key): User
    {
        $id = $this->history($f)->keys()->first(fn ($id) => hash_equals($this->clientKey($f, (int) $id), $key));
        // A client who stopped this freelancer's offers looks like any other unknown key.
        if ($id === null || DB::table('freelance_special_offer_mutes')->where('client_id', $id)->where('freelancer_id', $f->id)->exists()) {
            throw new FreelanceException('special_offer_client_unknown', 422);
        }

        return User::query()->findOrFail($id);
    }

    private function minPrice(int $countryId): ?int
    {
        $min = $this->policies->for($countryId, null)->get('project.min_budget_minor');

        return $min === null ? null : (int) $min;
    }

    private function decimals(Freelancer $f): int
    {
        return app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->decimals();
    }

    private function pseudoProject(Freelancer $f, ?int $specialtyId): Project
    {
        $code = app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code();

        return new Project(['country_id' => (int) $f->country_id, 'freelance_specialty_id' => $specialtyId, 'currency_code' => $code]);
    }

    private function locked(SpecialOffer $offer): SpecialOffer
    {
        return SpecialOffer::query()->whereKey($offer->id)->lockForUpdate()->with(['freelancer', 'client'])->firstOrFail();
    }

    private function clean(?string $text, int $max = 1000): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = trim(strip_tags($text));

        return $t === '' ? null : mb_substr($t, 0, $max);
    }
}
