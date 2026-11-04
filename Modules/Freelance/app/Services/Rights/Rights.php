<?php

namespace Modules\Freelance\Services\Rights;

use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as MilestoneStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProjectApproval;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\User\Models\User;

/**
 * Ownership and licences (FL-L13). The freelancer says on an offer or a ready-made service who owns the work once it is paid and what
 * the client may do with it: `transfer` (the client owns it), `license` (the freelancer owns it and the client may use it within written
 * limits) or `retained` (the freelancer owns it and the client gets a lasting, non-exclusive right). The agreement keeps those terms and
 * the standard text of that day, frozen like its policy.
 *
 * The rights of a part pass to the client when it is approved and paid (any way it gets approved); until then they only look at it.
 * With every delivery the freelancer confirms the work is theirs and lists any outside material. A finished work may be shown in the
 * freelancer's portfolio only while the client allows it, and the client can take that back at any time. Everything here applies only
 * where the `rights` switch is on; an agreement made without terms (`rights` null) works exactly as before.
 */
class Rights
{
    public const TRANSFER = 'transfer';

    public const LICENSE = 'license';

    public const RETAINED = 'retained';

    public const MODELS = [self::TRANSFER, self::LICENSE, self::RETAINED];

    public const WORLDWIDE = 'worldwide';

    public const COUNTRY = 'country';

    public const TERRITORIES = [self::WORLDWIDE, self::COUNTRY];

    public function __construct(
        private readonly FreelanceFeatures $features,
        private readonly RightsTerms $terms,
        private readonly FreelanceNotifier $notifier,
    ) {}

    public function enabled(int $countryId): bool
    {
        return $this->features->allows($countryId, 'rights');
    }

    // -------------------------------------------------------------- the terms an offer / a service carries

    /**
     * The terms as they are saved: only the fields that belong to the model, nothing the model ignores. Nothing given means the platform's
     * defaults (full transfer, no portfolio).
     *
     * @param  array<string, mixed>|null  $in
     * @return array{model: string, commercial: bool, exclusive: bool, term_months: ?int, territory: string, portfolio: bool}
     */
    public function normalize(?array $in): array
    {
        $in ??= [];
        $model = (string) ($in['model'] ?? config('freelance.rights.default_model', self::TRANSFER));
        if (! in_array($model, self::MODELS, true)) {
            throw new FreelanceException('rights_model_invalid', 422);
        }
        $out = ['model' => $model, 'commercial' => true, 'exclusive' => false, 'term_months' => null, 'territory' => self::WORLDWIDE, 'portfolio' => self::flag($in['portfolio'] ?? false)];
        if ($model === self::TRANSFER) {
            // The whole of it goes to the client: nothing left to limit.
            $out['exclusive'] = true;

            return $out;
        }
        $out['commercial'] = self::flag($in['commercial'] ?? true);
        $territory = (string) ($in['territory'] ?? self::WORLDWIDE);
        if (! in_array($territory, self::TERRITORIES, true)) {
            throw new FreelanceException('rights_territory_invalid', 422);
        }
        $out['territory'] = $territory;
        if ($model === self::LICENSE) {
            $out['exclusive'] = self::flag($in['exclusive'] ?? false);
            $months = $in['term_months'] ?? null;
            if ($months !== null && $months !== '' && $months !== 0 && $months !== '0') {
                $max = (int) config('freelance.rights.max_term_months', 120);
                if (! is_numeric($months) || (int) $months < 1 || (int) $months > $max) {
                    throw new FreelanceException('rights_term_invalid', 422, ['max' => $max], ['max' => $max]);
                }
                $out['term_months'] = (int) $months;
            }
        }

        return $out;
    }

    /**
     * What an offer or service is saved with in this country: the terms normalized, or null where the switch is off (nothing is kept).
     *
     * @param  array<string, mixed>|null  $in
     * @return ?array<string, mixed>
     */
    public function forListing(int $countryId, ?array $in): ?array
    {
        return $this->enabled($countryId) ? $this->normalize($in) : null;
    }

    /**
     * The terms a listing shows and an order starts from: what it carries, or the defaults when it carries none and the switch is on
     * (a listing made before the switch was on); null where the switch is off.
     *
     * @param  ?array<string, mixed>  $stored
     * @return ?array<string, mixed>
     */
    public function effective(?array $stored, int $countryId): ?array
    {
        return $stored ?? ($this->enabled($countryId) ? $this->normalize(null) : null);
    }

    // -------------------------------------------------------------- the agreement

    /**
     * What the agreement keeps (made inside the choosing transaction): the offer's terms with the standard text of today. An offer made
     * before the switch was on gets the defaults; where it is off and the offer has none, the agreement has none.
     *
     * @return ?array<string, mixed>
     */
    public function snapshot(FreelanceOffer $offer, int $countryId): ?array
    {
        $terms = $this->effective($offer->rights, $countryId);
        if ($terms === null) {
            return null;
        }

        return $terms + ['text' => $this->terms->snapshot($countryId, (string) $terms['model'])];
    }

    /** Whether this agreement carries terms (and so a delivery needs the freelancer's word). */
    public static function has(Agreement $a): bool
    {
        return is_array($a->rights) && ($a->rights['model'] ?? null) !== null;
    }

    /**
     * The summary of an agreement's terms, for the side looking at it.
     *
     * @param  'client'|'freelancer'|'admin'  $side
     * @return ?array<string, mixed>
     */
    public function present(Agreement $a, string $side): ?array
    {
        if (! self::has($a)) {
            return null;
        }
        $r = (array) $a->rights;
        $parts = Milestone::query()->where('agreement_id', $a->id)->where('status', '!=', MilestoneStatus::Cancelled->value)->get(['status']);
        $granted = $parts->filter(fn ($m) => $m->status === MilestoneStatus::Approved)->count();
        $asked = $a->portfolio_asked_at;
        $text = RightsTerms::localized((array) ($r['text'] ?? []));

        return [
            'model' => $r['model'],
            'model_label' => __('freelance.rights.models.'.$r['model']),
            'summary' => __('freelance.rights.summaries.'.$r['model']),
            'clauses' => self::clauses($r),
            'details' => ['commercial' => (bool) $r['commercial'], 'exclusive' => (bool) $r['exclusive'], 'term_months' => $r['term_months'] ?? null, 'territory' => $r['territory']],
            'terms' => $text,
            'portfolio' => ['wanted' => (bool) ($r['portfolio'] ?? false), 'allowed' => (bool) $a->portfolio_allowed, 'asked_at' => $asked?->toIso8601String()],
            // Before the first part is paid the client only looks; each approved part is theirs (or licensed) from then.
            'parts' => ['total' => $parts->count(), 'granted' => $granted],
            'state' => $granted === 0 ? 'pending' : ($granted >= $parts->count() ? 'granted' : 'partial'),
            'can' => [
                'portfolio_toggle' => $side === 'client' && in_array($a->status, [Agreement::DRAFT, Agreement::PENDING, Agreement::ACTIVE, Agreement::COMPLETED], true),
                'portfolio_request' => $side === 'freelancer' && in_array($a->status, [Agreement::ACTIVE, Agreement::COMPLETED], true) && ! $a->portfolio_allowed && $this->mayAskAgain($a),
            ],
        ];
    }

    /**
     * The terms of an offer or a service as lines the apps print (already in the request's language).
     *
     * @param  array<string, mixed>  $r
     * @return list<string>
     */
    public static function clauses(array $r): array
    {
        $model = (string) ($r['model'] ?? self::TRANSFER);
        $lines = [__('freelance.rights.clauses.owner_'.($model === self::TRANSFER ? 'client' : 'freelancer'))];
        if ($model === self::TRANSFER) {
            $lines[] = __('freelance.rights.clauses.use_everything');
        } else {
            $lines[] = __('freelance.rights.clauses.use_'.(($r['commercial'] ?? true) ? 'commercial' : 'personal'));
            $lines[] = __('freelance.rights.clauses.'.(($r['exclusive'] ?? false) ? 'exclusive' : 'non_exclusive'));
            $months = $r['term_months'] ?? null;
            $lines[] = $months === null ? __('freelance.rights.clauses.term_perpetual') : __('freelance.rights.clauses.term_months', ['n' => $months]);
            $lines[] = __('freelance.rights.clauses.territory_'.($r['territory'] ?? self::WORLDWIDE));
        }
        $lines[] = __('freelance.rights.clauses.after_payment');
        $lines[] = __('freelance.rights.clauses.portfolio_'.(($r['portfolio'] ?? false) ? 'wished' : 'none'));

        return $lines;
    }

    /**
     * What the offer and service screens show of the terms (null where there are none).
     *
     * @param  ?array<string, mixed>  $r
     * @return ?array<string, mixed>
     */
    public static function summary(?array $r): ?array
    {
        if (! is_array($r) || ($r['model'] ?? null) === null) {
            return null;
        }

        return [
            'model' => $r['model'], 'model_label' => __('freelance.rights.models.'.$r['model']), 'summary' => __('freelance.rights.summaries.'.$r['model']),
            'clauses' => self::clauses($r),
            'details' => ['commercial' => (bool) $r['commercial'], 'exclusive' => (bool) $r['exclusive'], 'term_months' => $r['term_months'] ?? null, 'territory' => $r['territory']],
            'portfolio' => (bool) ($r['portfolio'] ?? false),
        ];
    }

    /**
     * What the composer needs: the models with their words, the standard text of this country for each, and the limits.
     *
     * @return array<string, mixed>
     */
    public function options(int $countryId): array
    {
        return [
            'enabled' => $this->enabled($countryId),
            'default' => $this->normalize(null),
            'models' => array_map(fn (string $m) => [
                'model' => $m, 'label' => __('freelance.rights.models.'.$m), 'summary' => __('freelance.rights.summaries.'.$m),
                'terms' => RightsTerms::localized($this->terms->snapshot($countryId, $m)),
            ], self::MODELS),
            'limits' => ['max_term_months' => (int) config('freelance.rights.max_term_months', 120), 'max_materials' => (int) config('freelance.rights.max_materials', 10)],
        ];
    }

    // -------------------------------------------------------------- delivery: the freelancer's word

    /**
     * Checked with every delivery of an agreement that carries terms: the freelancer says the work is theirs (or that they may hand it
     * over) and lists any outside material (name, where it came from, its licence). Returns what is kept with the delivery.
     *
     * @param  array<string, mixed>|null  $in  {confirmed: bool, materials?: list<{name, source?, license?}>}
     * @return ?array{confirmed_at: string, materials: list<array{name: string, source: ?string, license: ?string}>}
     */
    public function declaration(Agreement $a, ?array $in): ?array
    {
        if (! self::has($a)) {
            return null;
        }
        if (! self::flag($in['confirmed'] ?? false)) {
            throw new FreelanceException('rights_declaration_required', 422);
        }
        $max = (int) config('freelance.rights.max_materials', 10);
        $materials = collect((array) ($in['materials'] ?? []))->filter(fn ($m) => is_array($m))->map(fn (array $m) => [
            'name' => mb_substr(trim(strip_tags((string) ($m['name'] ?? ''))), 0, 120),
            'source' => ($s = mb_substr(trim(strip_tags((string) ($m['source'] ?? ''))), 0, 200)) === '' ? null : $s,
            'license' => ($l = mb_substr(trim(strip_tags((string) ($m['license'] ?? ''))), 0, 120)) === '' ? null : $l,
        ])->filter(fn (array $m) => $m['name'] !== '')->take($max)->values()->all();

        return ['confirmed_at' => now()->toIso8601String(), 'materials' => $materials];
    }

    // -------------------------------------------------------------- the rights pass

    /** Inside the approving transaction: the record that this part's rights are the client's now. */
    public function recordGrant(Agreement $a, Milestone $m): void
    {
        if (! self::has($a)) {
            return;
        }
        FreelanceProjectApproval::forAgreement($a, 'rights.granted', 'system', null, ['milestone' => $m->uuid, 'model' => ((array) $a->rights)['model']]);
    }

    /** After it committed: the client is told. */
    public function announceGrant(Agreement $a, Milestone $m): void
    {
        if (! self::has($a)) {
            return;
        }
        $this->notifier->notify($a->client, 'rights.granted', ['project' => (string) $a->project->title], ['project' => $a->project->uuid, 'milestone' => $m->uuid]);
    }

    // -------------------------------------------------------------- the portfolio

    /** The client allows (or takes back) showing the work in the freelancer's portfolio. */
    public function setPortfolio(Agreement $agreement, User $client, bool $allowed): Agreement
    {
        abort_unless((int) $agreement->client_id === (int) $client->id, 404);
        $changed = DB::transaction(function () use ($agreement, $client, $allowed): ?Agreement {
            $a = Agreement::query()->whereKey($agreement->id)->lockForUpdate()->with(['project', 'freelancer'])->firstOrFail();
            if (! self::has($a)) {
                throw new FreelanceException('rights_not_available', 409);
            }
            if (! in_array($a->status, [Agreement::DRAFT, Agreement::PENDING, Agreement::ACTIVE, Agreement::COMPLETED], true)) {
                throw new FreelanceException('agreement_invalid_state', 409, ['status' => __('freelance.agreement_statuses.'.$a->status)], ['status' => $a->status]);
            }
            if ((bool) $a->portfolio_allowed === $allowed) {
                return null;
            }
            $a->forceFill(['portfolio_allowed' => $allowed])->save();
            FreelanceProjectApproval::forAgreement($a, $allowed ? 'rights.portfolio_granted' : 'rights.portfolio_revoked', 'client', $client->id);

            return $a;
        });
        if ($changed !== null && $changed->status !== Agreement::DRAFT) {
            $this->notifier->notify($changed->freelancer, $allowed ? 'rights.portfolio_granted' : 'rights.portfolio_revoked', ['project' => (string) $changed->project->title], ['project' => $changed->project->uuid]);
        }

        return $changed ?? $agreement->refresh();
    }

    /** The freelancer asks the client's leave to show the work (not again before a few days pass). */
    public function askPortfolio(Agreement $agreement, Freelancer $freelancer): Agreement
    {
        abort_unless((int) $agreement->freelancer_id === (int) $freelancer->id, 404);
        $asked = DB::transaction(function () use ($agreement, $freelancer): Agreement {
            $a = Agreement::query()->whereKey($agreement->id)->lockForUpdate()->with(['project', 'client'])->firstOrFail();
            if (! self::has($a)) {
                throw new FreelanceException('rights_not_available', 409);
            }
            if (! in_array($a->status, [Agreement::ACTIVE, Agreement::COMPLETED], true)) {
                throw new FreelanceException('agreement_invalid_state', 409, ['status' => __('freelance.agreement_statuses.'.$a->status)], ['status' => $a->status]);
            }
            if ($a->portfolio_allowed) {
                throw new FreelanceException('rights_portfolio_already_allowed', 409);
            }
            if (! $this->mayAskAgain($a)) {
                $days = (int) config('freelance.rights.ask_again_days', 3);
                throw new FreelanceException('rights_portfolio_asked_recently', 429, ['days' => $days], ['days' => $days]);
            }
            $a->forceFill(['portfolio_asked_at' => now()])->save();
            FreelanceProjectApproval::forAgreement($a, 'rights.portfolio_requested', 'freelancer', $freelancer->id);

            return $a;
        });
        $this->notifier->notify($asked->client, 'rights.portfolio_requested', ['project' => (string) $asked->project->title, 'name' => (string) $freelancer->name], ['project' => $asked->project->uuid]);

        return $asked;
    }

    /**
     * A finished work the freelancer may put in their portfolio: theirs, completed, and the client allows it.
     *
     * @throws FreelanceException
     */
    public function portfolioSource(Freelancer $freelancer, string $agreementUuid): Agreement
    {
        $a = Agreement::query()->where('uuid', $agreementUuid)->where('freelancer_id', $freelancer->id)->with('project:id,title')->first();
        if ($a === null) {
            throw new FreelanceException('rights_portfolio_source_invalid', 422);
        }
        if ($a->status !== Agreement::COMPLETED) {
            throw new FreelanceException('rights_portfolio_not_finished', 422);
        }
        if (! self::has($a) || ! $a->portfolio_allowed) {
            throw new FreelanceException('rights_portfolio_not_allowed', 422);
        }

        return $a;
    }

    /**
     * The finished works the freelancer may show (and whether each one is already on the profile), for the portfolio form.
     *
     * @return list<array<string, mixed>>
     */
    public function portfolioSources(Freelancer $freelancer): array
    {
        $used = $freelancer->portfolioItems()->whereNotNull('agreement_id')->pluck('agreement_id')->all();

        return Agreement::query()->where('freelancer_id', $freelancer->id)->where('status', Agreement::COMPLETED)->where('portfolio_allowed', true)->with('project:id,title')
            ->latest('completed_at')->limit(50)->get()
            ->map(fn (Agreement $a) => ['uuid' => $a->uuid, 'title' => (string) $a->project?->title, 'completed_at' => $a->completed_at?->toIso8601String(), 'used' => in_array($a->id, $used, true)])->values()->all();
    }

    private function mayAskAgain(Agreement $a): bool
    {
        return $a->portfolio_asked_at === null || $a->portfolio_asked_at->copy()->addDays((int) config('freelance.rights.ask_again_days', 3))->isPast();
    }

    /** A form field that may arrive as true / "1" / "true" / "on". */
    private static function flag(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
