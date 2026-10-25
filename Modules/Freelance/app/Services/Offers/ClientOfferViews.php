<?php

namespace Modules\Freelance\Services\Offers;

use Illuminate\Support\Collection;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerExperience;
use Modules\Freelance\Services\Account\FreelancerCompleteness;
use Modules\Freelance\Services\Account\FreelancerPortfolio;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelanceFees;
use Modules\Freelance\Services\Policy\FreelancePolicies;

/**
 * The offers on a project as its client compares them (FL-305): each with what helps to choose — how complete and verified the
 * freelancer is, how many works they show, which of the project's skills they have — sorted and filtered, up to three side by side,
 * and the freelancer's profile as a client may read it (never a phone or an email). The rating (from three ratings, else null and the
 * app says «new») and the finished projects come from FL-701 / FL-703.
 */
class ClientOfferViews
{
    public const SORTS = ['newest', 'cheapest', 'fastest', 'completeness'];

    public const COMPARE_MAX = 3;

    public function __construct(
        private readonly FreelancerOffers $offers,
        private readonly FreelancerCompleteness $completeness,
        private readonly FreelancerPortfolio $portfolio,
        private readonly FreelancePolicies $policies,
        private readonly FreelanceFeatures $features,
    ) {}

    /** @return list<array<string, mixed>> the project's offers (not the ones taken back), filtered by status and sorted */
    public function list(Project $project, ?string $status, string $sort): array
    {
        $rows = $this->offers->forProject($project)
            ->when($status !== null && $status !== 'all', fn (Collection $c) => $c->filter(fn (Offer $o) => $o->status->value === $status))
            ->map(fn (Offer $o) => $this->present($project, $o))->values();

        $rows = match ($sort) {
            'cheapest' => $rows->sortBy([['price_minor', 'asc'], ['created_at', 'desc']]),
            'fastest' => $rows->sortBy([['duration_days', 'asc'], ['price_minor', 'asc']]),
            'completeness' => $rows->sortBy([[fn ($a, $b) => $b['freelancer']['completeness'] <=> $a['freelancer']['completeness']], ['created_at', 'desc']]),
            default => $rows,
        };

        return $rows->values()->all();
    }

    /**
     * @param  list<string>  $uuids  two or three offers of this project
     * @return array{offers: list<array<string, mixed>>, best: array<string, ?string>}
     */
    public function compare(Project $project, array $uuids): array
    {
        $uuids = array_values(array_unique($uuids));
        if (count($uuids) < 2 || count($uuids) > self::COMPARE_MAX) {
            throw new FreelanceException('offer_compare_count', 422, ['max' => self::COMPARE_MAX], ['max' => self::COMPARE_MAX]);
        }
        $found = $this->offers->forProject($project)->whereIn('uuid', $uuids);
        if ($found->count() !== count($uuids)) {
            abort(404);
        }
        // In the order the client picked them.
        $rows = collect($uuids)->map(fn (string $u) => $this->present($project, $found->firstWhere('uuid', $u)));

        return [
            'offers' => $rows->all(),
            // What stands out, for the app to mark (ties go to the first picked).
            'best' => [
                'price' => $rows->sortBy('price_minor')->first()['uuid'] ?? null,
                'duration' => $rows->sortBy('duration_days')->first()['uuid'] ?? null,
                'completeness' => $rows->sortByDesc(fn ($r) => $r['freelancer']['completeness'])->first()['uuid'] ?? null,
                'skills' => $rows->sortByDesc(fn ($r) => count($r['freelancer']['matching_skills']))->first()['uuid'] ?? null,
            ],
        ];
    }

    /** @return array<string, mixed> one offer for its client, with what the freelancer brings and what choosing it would cost */
    public function present(Project $project, Offer $offer): array
    {
        $out = $this->offers->present($offer, 'client', "projects/{$project->uuid}/offers/{$offer->uuid}/attachments");
        $f = $offer->freelancer;
        if ($f !== null) {
            $out['freelancer'] += $this->summary($f, $project);
            // FL-306: the client keeps this freelancer.
            $out['freelancer']['is_favorite'] = app(ClientFavorites::class)->has($project->client, $f->id);
        }
        $out['can'] = [
            'shortlist' => $offer->status->canMoveTo(OfferStatus::Shortlisted) && $project->status === \Modules\Freelance\Enums\FreelanceProjectStatus::Published,
            'unshortlist' => $offer->status === OfferStatus::Shortlisted && $project->status === \Modules\Freelance\Enums\FreelanceProjectStatus::Published,
            'reject' => $offer->status->canMoveTo(OfferStatus::Rejected) && $project->status === \Modules\Freelance\Enums\FreelanceProjectStatus::Published,
            'choose' => $offer->status->canMoveTo(OfferStatus::Accepted) && $project->status === \Modules\Freelance\Enums\FreelanceProjectStatus::Published,
        ];
        $out['client_cost'] = $this->cost($project, $offer->price_minor);
        // The talk about it (FL-308): null until the client opens it.
        $out['chat'] = app(\Modules\Freelance\Services\Chat\OfferChat::class)->summary($offer, 'client');
        $out['can']['chat'] = in_array($offer->status->value, ['submitted', 'viewed', 'shortlisted', 'accepted'], true) && in_array($project->status->value, ['published', 'awarded'], true);

        return $out;
    }

    /** @return array{price_minor: int, client_fee_minor: int, total_minor: int, currency: string} what the client pays if they choose it */
    public function cost(Project $project, int $priceMinor): array
    {
        $values = $this->policies->for($project->country_id, $project->freelance_specialty_id)->all();
        // Where the client fee switch is off, the client pays the price and nothing more.
        if (! $this->features->allows($project->country_id, 'client_fee')) {
            $values['client_fee.type'] = 'fixed';
            $values['client_fee.value'] = 0;
            $values['client_fee.min_minor'] = null;
        }
        $b = FreelanceFees::breakdown($priceMinor, $values);

        $cost = ['price_minor' => $b['amount_minor'], 'client_fee_minor' => $b['client_fee_minor'], 'total_minor' => $b['client_total_minor']];

        return $cost + ['currency' => $project->currency_code, 'lines' => FreelanceFees::lines('client', $cost)];
    }

    /** @return array<string, mixed> the freelancer's profile as the client of one of their offers reads it — no phone, no email */
    public function profile(Project $project, Offer $offer): array
    {
        $f = $offer->freelancer ?? abort(404);
        $f->loadMissing(['specialties.translations', 'skills.translations']);

        return [
            'uuid' => $f->uuid,
            'name' => $f->name,
            'headline' => $f->headline,
            'bio' => $f->bio,
            'avatar_url' => $f->getFirstMediaUrl('avatar') ?: null,
            'verified' => $f->status?->value === 'verified',
            'city' => $f->city,
            'languages' => array_values((array) $f->contact_languages),
            'member_since' => $f->created_at?->toDateString(),
            'experiences' => $f->experiences()->orderByDesc('is_current')->orderByDesc('started_on')->get()->map(fn (FreelancerExperience $e) => [
                'title' => $e->title, 'company' => $e->company, 'started_on' => $e->started_on?->format('Y-m'), 'ended_on' => $e->ended_on?->format('Y-m'), 'is_current' => (bool) $e->is_current, 'description' => $e->description,
            ])->values()->all(),
            'certificates' => $f->certificates()->orderByDesc('issued_on')->get()->map(fn ($c) => ['title' => $c->title, 'issuer' => $c->issuer, 'issued_on' => $c->issued_on?->format('Y-m')])->values()->all(),
            'portfolio' => $this->portfolio->visibleTo($f, true)->map(fn ($i) => $this->portfolio->present($i, false))->values()->all(),
            // FL-701: what clients said, once out; and the average by criterion.
            'reviews' => app(\Modules\Freelance\Services\Projects\FreelanceReviews::class)->latestAbout($f),
            'rating_criteria' => app(\Modules\Freelance\Services\Projects\FreelanceReviews::class)->stats($f)['criteria'],
            'is_favorite' => app(ClientFavorites::class)->has($project->client, $f->id),
        ] + $this->summary($f, $project);
    }

    /** @return array<string, mixed> what helps compare freelancers on this project */
    private function summary(Freelancer $f, Project $project): array
    {
        $f->loadMissing(['specialties.translations', 'skills.translations']);
        $projectSkills = $project->skills()->pluck('freelance_skills.id')->map(fn ($i) => (int) $i)->all();
        $mine = $f->skills;

        return [
            'completeness' => $this->completeness->of($f)['percent'],
            'years_of_experience' => $f->years_of_experience,
            'portfolio_count' => $this->portfolio->visibleTo($f, true)->count(),
            'specialties' => $f->specialties->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName()])->values()->all(),
            'skills' => $mine->map(fn (FreelanceSkill $s) => ['id' => $s->id, 'name' => $s->translatedName()])->values()->all(),
            // The project's skills this freelancer has.
            'matching_skills' => $mine->filter(fn (FreelanceSkill $s) => in_array((int) $s->id, $projectSkills, true))->map(fn (FreelanceSkill $s) => ['id' => $s->id, 'name' => $s->translatedName()])->values()->all(),
            'project_skills_count' => count($projectSkills),
        ] + array_intersect_key(
            // FL-701 / FL-703: the average (from three ratings), how many, finished projects, on time, and like this one.
            app(\Modules\Freelance\Services\Projects\FreelanceReviews::class)->stats($f, $project->freelance_specialty_id),
            array_flip(['rating', 'reviews_count', 'completed_projects', 'completion_rate', 'on_time_rate', 'similar_projects']),
        ) + [
        ];
    }
}
