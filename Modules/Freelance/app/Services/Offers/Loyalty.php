<?php

namespace Modules\Freelance\Services\Offers;

use App\Models\Country;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceConsultation as Consultation;
use Modules\Freelance\Models\FreelanceLoyaltyProgram;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;

/**
 * A freelancer's loyalty program (FL-L09, part B). Each freelancer sets their own steps: after this many works finished with the same
 * client, that client gets this much off — on a project offer, a ready-made service order and a consultation alike. The discount is the
 * freelancer's own (it comes out of the price they ask), so the commission and the client's fee are worked out on the price after it, as
 * anywhere. It is taken by itself: nobody has to ask for it, and the apps show the usual price struck through beside the price paid.
 *
 * What counts is the works finished together — a completed agreement or a completed consultation — the same history the special offers
 * read. A special offer's price is already the freelancer's own word, so a discount is never put on top of it; a monthly service keeps one
 * price for every month it runs, so it stays out. The platform only draws the limits (`loyalty.max_discount_bp`, `loyalty.max_tiers`) and
 * can switch the whole thing off per country (`loyalty`).
 */
class Loyalty
{
    /** The smallest step of discount (1%) and the furthest a step can wait (works finished together). */
    public const MIN_BP = 100;

    public const MAX_AFTER = 50;

    /** The consultation statuses where the time is held or done — where a discount was really taken. */
    private const KEPT = [Consultation::BOOKED, Consultation::COMPLETED, Consultation::DISPUTED];

    /** Ready-made ladders the setup screen offers: [after, discount in basis points] steps, cut down to the platform's limits. */
    private const PRESETS = [
        'gentle' => [[2, 300], [5, 600]],
        'balanced' => [[2, 500], [5, 1000], [10, 1500]],
        'generous' => [[1, 500], [3, 1000], [6, 1500], [10, 2000]],
    ];

    public function __construct(
        private readonly FreelancePolicies $policies,
        private readonly FreelanceFeatures $features,
        private readonly FreelanceNotifier $notifier,
    ) {}

    /** A price with a discount in basis points, to the nearest minor unit (never below one). */
    public static function discounted(int $priceMinor, int $discountBp): int
    {
        return max(1, intdiv($priceMinor * (10000 - $discountBp) + 5000, 10000));
    }

    /** 1500 → «15», 250 → «2.5». */
    public static function percent(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');
    }

    /** @return array{min_discount_bp: int, max_discount_bp: int, max_tiers: int, max_after: int} */
    public function rules(int $countryId): array
    {
        $p = $this->policies->for($countryId, null);

        return [
            'min_discount_bp' => self::MIN_BP,
            'max_discount_bp' => max(self::MIN_BP, (int) ($p->get('loyalty.max_discount_bp') ?? 3000)),
            'max_tiers' => max(1, (int) ($p->get('loyalty.max_tiers') ?? 4)),
            'max_after' => self::MAX_AFTER,
        ];
    }

    public function on(int $countryId): bool
    {
        return $this->features->allows($countryId, 'loyalty');
    }

    // ================================================================= the freelancer's program

    public function program(Freelancer $f): ?FreelanceLoyaltyProgram
    {
        return FreelanceLoyaltyProgram::query()->where('freelancer_id', $f->id)->first();
    }

    /**
     * Sets the program: the steps (checked against the platform's limits) and whether it is on. Always the whole ladder.
     *
     * @param  list<array{after?: mixed, discount_bp?: mixed}>  $tiers
     */
    public function save(Freelancer $f, bool $enabled, array $tiers): FreelanceLoyaltyProgram
    {
        $clean = $this->clean($tiers, $this->rules((int) $f->country_id));

        return DB::transaction(function () use ($f, $enabled, $clean) {
            // One saving at a time per freelancer: two taps make one program.
            Freelancer::query()->whereKey($f->id)->lockForUpdate()->first();
            $program = $this->program($f) ?? new FreelanceLoyaltyProgram(['freelancer_id' => $f->id]);
            $program->fill(['enabled' => $enabled, 'tiers' => $clean])->save();

            return $program;
        });
    }

    /** Switches an existing program on or off without touching its steps. */
    public function toggle(Freelancer $f, bool $enabled): FreelanceLoyaltyProgram
    {
        $program = $this->program($f) ?? throw new FreelanceException('loyalty_not_set_up', 409);
        $program->fill(['enabled' => $enabled])->save();

        return $program;
    }

    // ================================================================= what a client gets

    /** The works this freelancer and this client finished together: completed agreements and completed consultations. */
    public function worksWith(Freelancer $f, int $clientId): int
    {
        return Agreement::query()->where('freelancer_id', $f->id)->where('client_id', $clientId)->where('status', Agreement::COMPLETED)->count()
            + Consultation::query()->where('freelancer_id', $f->id)->where('client_id', $clientId)->where('status', Consultation::COMPLETED)->count();
    }

    /** The step this client has reached with this freelancer — null: no program, switched off here, or not far enough yet. @return array{bp: int, after: int, works: int}|null */
    public function discountFor(Freelancer $f, int $clientId): ?array
    {
        if (! $this->on((int) $f->country_id)) {
            return null;
        }
        $program = $this->program($f);
        if ($program === null || ! $program->enabled) {
            return null;
        }
        $tiers = $this->tiersOf($program);
        if ($tiers === []) {
            return null;
        }
        $works = $this->worksWith($f, $clientId);
        $tier = $this->tierAt($tiers, $works);

        return $tier === null ? null : ['bp' => $tier['discount_bp'], 'after' => $tier['after'], 'works' => $works];
    }

    /**
     * A price after the discount this client has earned with this freelancer (the price asked is `$listMinor`).
     *
     * @return array{price_minor: int, list_price_minor: ?int, loyalty_bp: ?int, saving_minor: int, tier: ?array{bp: int, after: int, works: int}}
     */
    public function apply(Freelancer $f, int $clientId, int $listMinor): array
    {
        $tier = $this->discountFor($f, $clientId);
        $price = $tier === null ? $listMinor : self::discounted($listMinor, $tier['bp']);
        // A price too small for the percent to show is left as it is.
        if ($tier === null || $price >= $listMinor) {
            return ['price_minor' => $listMinor, 'list_price_minor' => null, 'loyalty_bp' => null, 'saving_minor' => 0, 'tier' => null];
        }

        return ['price_minor' => $price, 'list_price_minor' => $listMinor, 'loyalty_bp' => $tier['bp'], 'saving_minor' => $listMinor - $price, 'tier' => $tier];
    }

    /**
     * The program as a client reads it before buying: its steps, how many works they finished with this freelancer, the step they hold
     * and the next one. Null when the freelancer has no program on (or the country has it off). Without a client it is the plain ladder.
     *
     * @return array<string, mixed>|null
     */
    public function view(Freelancer $f, ?User $client = null): ?array
    {
        if (! $this->on((int) $f->country_id)) {
            return null;
        }
        $program = $this->program($f);
        $tiers = $program === null || ! $program->enabled ? [] : $this->tiersOf($program);
        if ($tiers === []) {
            return null;
        }

        return $this->shape($tiers, $client === null ? 0 : $this->worksWith($f, (int) $client->id));
    }

    /**
     * Every freelancer this client finished work with who runs a program, the best discount first: what they hold and what is next.
     *
     * @return list<array<string, mixed>>
     */
    public function mine(User $client): array
    {
        $works = $this->clientWorks((int) $client->id);
        if ($works->isEmpty()) {
            return [];
        }
        $programs = FreelanceLoyaltyProgram::query()->whereIn('freelancer_id', $works->keys())->where('enabled', true)->get()->keyBy('freelancer_id');
        $freelancers = Freelancer::query()->whereIn('id', $programs->keys())->where('status', FreelancerStatus::Verified->value)->with('media')->get();

        return $freelancers->filter(fn (Freelancer $f) => $this->on((int) $f->country_id))->map(function (Freelancer $f) use ($programs, $works) {
            $tiers = $this->tiersOf($programs[$f->id]);

            return $tiers === [] ? null : ['freelancer' => [
                'uuid' => $f->uuid, 'name' => $f->name, 'headline' => $f->headline, 'avatar_url' => $f->getFirstMediaUrl('avatar') ?: null,
            ]] + $this->shape($tiers, (int) $works[$f->id]);
        })->filter()->sortBy([
            fn ($a, $b) => ($b['current']['discount_bp'] ?? 0) <=> ($a['current']['discount_bp'] ?? 0),
            fn ($a, $b) => $b['works'] <=> $a['works'],
        ])->values()->all();
    }

    // ================================================================= the freelancer's screen

    /**
     * What the setup screen reads: the program, the limits, ready-made ladders, the numbers and the clients who earned a step.
     *
     * @return array<string, mixed>
     */
    public function dashboard(Freelancer $f): array
    {
        $countryId = (int) $f->country_id;
        $country = Country::query()->with('currency')->find($countryId);
        $rules = $this->rules($countryId);
        $program = $this->program($f);
        $tiers = $program === null ? [] : $this->tiersOf($program);
        $works = $this->freelancerWorks($f);

        return [
            'available' => $this->on($countryId),
            'currency' => $country?->currency?->code,
            'decimals' => (int) ($country?->currency?->decimal_places ?? 2),
            'program' => $program === null ? null : ['enabled' => (bool) $program->enabled, 'tiers' => $tiers],
            'rules' => $rules,
            'presets' => $this->presets($rules),
            'stats' => $this->stats($f, $tiers, $works),
            'clients' => $this->clientsOf($works, $tiers),
        ];
    }

    /** @return list<array{key: string, name: string, tiers: list<array{after: int, discount_bp: int}>}> */
    public function presets(array $rules): array
    {
        return collect(self::PRESETS)->map(function (array $steps, string $key) use ($rules) {
            $tiers = collect($steps)->map(fn (array $s) => ['after' => $s[0], 'discount_bp' => min($s[1], $rules['max_discount_bp'])])
                ->take($rules['max_tiers'])->values()->all();

            return ['key' => $key, 'name' => __('freelance.loyalty_presets.'.$key), 'tiers' => $this->strictlyRising($tiers)];
        })->values()->all();
    }

    // ================================================================= when work ends

    /** A completed work: a client who has just reached a step of this freelancer's program is told (once, as it is reached). */
    public function unlocked(int $freelancerId, int $clientId): void
    {
        $f = Freelancer::query()->find($freelancerId);
        $client = User::query()->find($clientId);
        if ($f === null || $client === null || ! $this->on((int) $f->country_id)) {
            return;
        }
        $program = $this->program($f);
        if ($program === null || ! $program->enabled) {
            return;
        }
        $works = $this->worksWith($f, $clientId);
        $step = collect($this->tiersOf($program))->firstWhere('after', $works);
        if ($step === null) {
            return;
        }
        $this->notifier->notify($client, 'loyalty.unlocked', ['name' => (string) $f->name, 'percent' => self::percent($step['discount_bp'])], ['loyalty' => $f->uuid]);
    }

    // ================================================================= staff

    /**
     * How many freelancers run a program on, how many times a discount was taken and what it saved — per currency, since the countries differ.
     *
     * @return array{freelancers: int, uses: int, saved: list<array{currency: string, decimals: int, saved_minor: int}>}
     */
    public function totals(): array
    {
        $rows = $this->taken()->groupBy('currency_code');
        $decimals = Country::query()->with('currency')->get()->mapWithKeys(fn ($c) => [(string) $c->currency?->code => (int) ($c->currency?->decimal_places ?? 2)]);

        return [
            'freelancers' => FreelanceLoyaltyProgram::query()->where('enabled', true)->count(),
            'uses' => $this->taken()->count(),
            'saved' => $rows->map(fn ($g, $code) => ['currency' => (string) $code, 'decimals' => (int) ($decimals[$code] ?? 2), 'saved_minor' => (int) $g->sum('saved')])->values()->all(),
        ];
    }

    /**
     * What each of these freelancers gave away: discounts taken and what they came to, in the currency they were taken in.
     *
     * @param  list<int>  $freelancerIds
     * @return array<int, array{uses: int, saved_minor: int, currency: ?string}>
     */
    public function usageOf(array $freelancerIds): array
    {
        return $this->taken($freelancerIds)->groupBy('freelancer_id')->map(fn ($g) => [
            'uses' => $g->count(), 'saved_minor' => (int) $g->sum('saved'), 'currency' => $g->first()->currency_code,
        ])->all();
    }

    /** Every discount really taken (an accepted offer, a held or done consultation): freelancer, currency and what it took off. */
    private function taken(?array $freelancerIds = null): Collection
    {
        $offers = Offer::query()->whereNotNull('loyalty_bp')->where('status', OfferStatus::Accepted->value)
            ->when($freelancerIds !== null, fn ($q) => $q->whereIn('freelancer_id', $freelancerIds))
            ->get(['freelancer_id', 'currency_code', 'list_price_minor', 'price_minor']);
        $consultations = Consultation::query()->whereNotNull('loyalty_bp')->whereIn('status', self::KEPT)
            ->when($freelancerIds !== null, fn ($q) => $q->whereIn('freelancer_id', $freelancerIds))
            ->get(['freelancer_id', 'currency_code', 'list_price_minor', 'price_minor']);

        return $offers->concat($consultations)->map(fn ($r) => (object) [
            'freelancer_id' => (int) $r->freelancer_id, 'currency_code' => (string) $r->currency_code, 'saved' => max(0, (int) $r->list_price_minor - (int) $r->price_minor),
        ]);
    }

    // ================================================================= inside

    /** @param  list<array{after: int, discount_bp: int}>  $tiers */
    private function shape(array $tiers, int $works): array
    {
        $current = $this->tierAt($tiers, $works);
        $next = collect($tiers)->first(fn (array $t) => $t['after'] > $works);
        $base = $current['after'] ?? 0;

        return [
            'works' => $works,
            'tiers' => array_map(fn (array $t) => $t + ['reached' => $works >= $t['after']], $tiers),
            'current' => $current,
            'next' => $next === null ? null : $next + ['remaining' => $next['after'] - $works],
            'progress' => $next === null ? 1.0 : round(max(0, $works - $base) / max(1, $next['after'] - $base), 2),
        ];
    }

    /** @return list<array{after: int, discount_bp: int}> the steps as stored, first step first */
    private function tiersOf(FreelanceLoyaltyProgram $program): array
    {
        return collect((array) $program->tiers)->map(fn ($t) => ['after' => (int) ($t['after'] ?? 0), 'discount_bp' => (int) ($t['discount_bp'] ?? 0)])
            ->filter(fn (array $t) => $t['after'] >= 1 && $t['discount_bp'] >= 1)->sortBy('after')->values()->all();
    }

    /** The highest step reached after this many works. @param list<array{after: int, discount_bp: int}> $tiers */
    private function tierAt(array $tiers, int $works): ?array
    {
        return collect($tiers)->filter(fn (array $t) => $t['after'] <= $works)->last();
    }

    /**
     * @param  list<array{after?: mixed, discount_bp?: mixed}>  $tiers
     * @param  array{min_discount_bp: int, max_discount_bp: int, max_tiers: int, max_after: int}  $rules
     * @return list<array{after: int, discount_bp: int}>
     */
    private function clean(array $tiers, array $rules): array
    {
        if ($tiers === []) {
            throw new FreelanceException('loyalty_tiers_required', 422);
        }
        if (count($tiers) > $rules['max_tiers']) {
            throw new FreelanceException('loyalty_tiers_many', 422, ['max' => $rules['max_tiers']], ['max' => $rules['max_tiers']]);
        }
        $out = [];
        foreach ($tiers as $t) {
            $after = (int) ($t['after'] ?? 0);
            $bp = (int) ($t['discount_bp'] ?? 0);
            if ($after < 1 || $after > $rules['max_after']) {
                throw new FreelanceException('loyalty_after_invalid', 422, ['max' => $rules['max_after']], ['max' => $rules['max_after']]);
            }
            if ($bp < $rules['min_discount_bp'] || $bp > $rules['max_discount_bp']) {
                throw new FreelanceException('loyalty_discount_invalid', 422, ['min' => self::percent($rules['min_discount_bp']), 'max' => self::percent($rules['max_discount_bp'])],
                    ['min_bp' => $rules['min_discount_bp'], 'max_bp' => $rules['max_discount_bp']]);
            }
            $out[] = ['after' => $after, 'discount_bp' => $bp];
        }
        usort($out, fn (array $a, array $b) => $a['after'] <=> $b['after']);
        // A client who comes back more never gets less: each step is further out and bigger than the one before.
        if (count($out) !== count($this->strictlyRising($out))) {
            throw new FreelanceException('loyalty_tiers_order', 422);
        }

        return $out;
    }

    /** The steps kept while they keep rising in both. @param list<array{after: int, discount_bp: int}> $tiers @return list<array{after: int, discount_bp: int}> */
    private function strictlyRising(array $tiers): array
    {
        $out = [];
        foreach ($tiers as $t) {
            $last = end($out);
            if ($last === false || ($t['after'] > $last['after'] && $t['discount_bp'] > $last['discount_bp'])) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** freelancer_id => works this client finished with them. @return Collection<int, int> */
    private function clientWorks(int $clientId): Collection
    {
        $agreements = Agreement::query()->where('client_id', $clientId)->where('status', Agreement::COMPLETED)->selectRaw('freelancer_id, count(*) as n')->groupBy('freelancer_id')->pluck('n', 'freelancer_id');
        $consultations = Consultation::query()->where('client_id', $clientId)->where('status', Consultation::COMPLETED)->selectRaw('freelancer_id, count(*) as n')->groupBy('freelancer_id')->pluck('n', 'freelancer_id');

        return $agreements->keys()->merge($consultations->keys())->unique()->mapWithKeys(fn ($id) => [(int) $id => (int) ($agreements[$id] ?? 0) + (int) ($consultations[$id] ?? 0)]);
    }

    /** client_id => works finished with this freelancer. @return Collection<int, int> */
    private function freelancerWorks(Freelancer $f): Collection
    {
        $agreements = Agreement::query()->where('freelancer_id', $f->id)->where('status', Agreement::COMPLETED)->selectRaw('client_id, count(*) as n')->groupBy('client_id')->pluck('n', 'client_id');
        $consultations = Consultation::query()->where('freelancer_id', $f->id)->where('status', Consultation::COMPLETED)->selectRaw('client_id, count(*) as n')->groupBy('client_id')->pluck('n', 'client_id');

        return $agreements->keys()->merge($consultations->keys())->unique()->mapWithKeys(fn ($id) => [(int) $id => (int) ($agreements[$id] ?? 0) + (int) ($consultations[$id] ?? 0)]);
    }

    /**
     * @param  list<array{after: int, discount_bp: int}>  $tiers
     * @param  Collection<int, int>  $works  client_id => works
     * @return array<string, mixed>
     */
    private function stats(Freelancer $f, array $tiers, Collection $works): array
    {
        $offers = Offer::query()->where('freelancer_id', $f->id)->whereNotNull('loyalty_bp')->where('status', OfferStatus::Accepted->value)
            ->selectRaw('count(*) as n, coalesce(sum(list_price_minor - price_minor), 0) as saved')->first();
        $consultations = Consultation::query()->where('freelancer_id', $f->id)->whereNotNull('loyalty_bp')->whereIn('status', self::KEPT)
            ->selectRaw('count(*) as n, coalesce(sum(list_price_minor - price_minor), 0) as saved')->first();
        $held = $works->map(fn (int $n) => $this->tierAt($tiers, $n));

        return [
            'past_clients' => $works->count(),
            'in_program' => $held->filter()->count(),
            'uses' => (int) $offers->n + (int) $consultations->n,
            'saved_minor' => (int) $offers->saved + (int) $consultations->saved,
            // How many clients stand on each step.
            'by_tier' => array_map(fn (array $t) => $t + ['clients' => $held->filter(fn ($h) => $h !== null && $h['after'] === $t['after'])->count()], $tiers),
        ];
    }

    /**
     * The clients with the most works together (at most 30), each with the step they hold — a first name and a picture, never a way to reach them.
     *
     * @param  Collection<int, int>  $works
     * @param  list<array{after: int, discount_bp: int}>  $tiers
     * @return list<array<string, mixed>>
     */
    private function clientsOf(Collection $works, array $tiers): array
    {
        $top = $works->sortDesc()->take(30);
        $users = User::query()->whereIn('id', $top->keys())->with('media')->get()->keyBy('id');

        return $top->map(function (int $n, int $id) use ($users, $tiers) {
            $u = $users->get($id);
            if ($u === null) {
                return null;
            }
            $shape = $tiers === [] ? ['current' => null, 'next' => null] : $this->shape($tiers, $n);

            return ['name' => (string) $u->name, 'avatar_url' => $u->getSingleMediaUrl('avatar') ?: null, 'works' => $n, 'current' => $shape['current'], 'next' => $shape['next']];
        })->filter()->values()->all();
    }
}
