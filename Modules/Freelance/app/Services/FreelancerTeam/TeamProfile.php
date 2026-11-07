<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use App\Services\General\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceProjectMember as ProjectMember;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem as Item;
use Modules\Freelance\Services\Account\FreelancerPortfolio;
use Modules\Freelance\Services\Projects\FreelanceReviews;
use Modules\Freelance\Services\Team\ClientTeamAbilities;
use Modules\User\Models\User;

/**
 * FS-202a: a freelancers' team's profile - what it tells about itself and who may read it.
 *
 *  - The Owner writes it (`edit_team`, never a PM or a member): name, bio, logo, public or private, open to new projects or not. A suspended
 *    or archived team changes nothing (409).
 *  - Specialties, skills and languages are not typed in: they are the union of the active members' (D10), so they follow the people.
 *  - Ratings and figures are the team's (D3, `FreelanceReviews::teamStats`), never the Owner's personal ones.
 *  - Who reads it: the team's active people always (even a suspended team); outside it, only an active team, and then a `public` one to any
 *    signed-in client or freelancer, a `private` one only to a client the team sent an offer to (one taken back before he saw it does not
 *    count). Anybody else gets 404, as if there were no team.
 *  - A member is shown to people outside the team only if he agreed (`show_in_profile`, D10, off by default) - and then by first name, role,
 *    headline and photo; the others are only counted. Nobody turns this on for somebody else.
 */
class TeamProfile
{
    public const BIO_MAX = 2000;

    public const UPDATED = 'freelance.team.profile_updated';

    public const LOGO_CHANGED = 'freelance.team.logo_changed';

    // Who reads the profile.
    public const MEMBER = 'member';

    public const CLIENT = 'client';

    public const FREELANCER = 'freelancer';

    private const SPECIALTIES_MAX = 10;

    private const SKILLS_MAX = 20;

    private const REVIEWS_MAX = 5;

    /** @var array<int, array<string, mixed>> the short version, once per team and request (an offers list repeats teams) */
    private array $summaries = [];

    public function __construct(
        private readonly FreelancerTeams $teams,
        private readonly TeamAbilities $abilities,
        private readonly FreelanceReviews $reviews,
        private readonly FreelancerPortfolio $portfolio,
        private readonly AuditLogger $audit,
    ) {}

    // -------------------------------------------------------------- writing

    /**
     * @param  array{name?: string, bio?: ?string, visibility?: string, accepting_projects?: bool}  $data  already validated
     */
    public function update(FreelanceTeam $team, Freelancer $by, array $data): FreelanceTeam
    {
        $this->assertEditable($team, $by);
        $fields = ['name', 'bio', 'visibility', 'accepting_projects'];
        $before = $team->only($fields);

        $changes = [];
        if (array_key_exists('name', $data)) {
            $changes['name'] = $this->teams->cleanName((string) $data['name']);
        }
        if (array_key_exists('bio', $data)) {
            // Plain text only: tags out, line breaks kept.
            $text = $data['bio'] === null ? '' : trim(strip_tags((string) $data['bio']));
            $changes['bio'] = $text === '' ? null : mb_substr($text, 0, self::BIO_MAX);
        }
        if (array_key_exists('visibility', $data)) {
            $changes['visibility'] = (string) $data['visibility'];
        }
        if (array_key_exists('accepting_projects', $data)) {
            $changes['accepting_projects'] = (bool) $data['accepting_projects'];
        }
        $team->update($changes);

        $after = $team->only($fields);
        if ($after != $before) {
            $this->audit->record(self::UPDATED, $team, $before, $after + ['by_freelancer_id' => $by->id]);
        }
        unset($this->summaries[$team->id]);

        return $team;
    }

    public function setLogo(FreelanceTeam $team, Freelancer $by, ?UploadedFile $file): FreelanceTeam
    {
        $this->assertEditable($team, $by);
        $file === null ? $team->deleteSingleMedia('logo') : $team->setSingleMedia('logo', $file);
        $this->audit->record(self::LOGO_CHANGED, $team, null, ['logo' => $file !== null, 'by_freelancer_id' => $by->id]);
        unset($this->summaries[$team->id]);

        return $team->refresh();
    }

    /** D10: the person himself says whether he is shown on the team's profile (also in a suspended team - it is his own data). */
    public function consent(FreelanceTeam $team, Freelancer $who, bool $show): Member
    {
        $row = $this->teams->membership($team, $who) ?? throw new FreelanceException('freelancer_team_not_member', 404);
        $row->forceFill(['show_in_profile' => $show])->save();

        return $row;
    }

    // -------------------------------------------------------------- reading

    /**
     * How this person reads the team's profile (`member`, `client` or `freelancer`), or null when he may not.
     */
    public function viewer(FreelanceTeam $team, ?Freelancer $freelancer = null, ?User $client = null): ?string
    {
        if ($team->trashed()) {
            return null;
        }
        if ($freelancer !== null && $this->teams->isMember($team, $freelancer)) {
            return self::MEMBER;
        }
        if (! $team->isActive()) {
            return null;
        }
        if ($client !== null) {
            return $team->visibility === FreelanceTeam::PUBLIC || $this->madeAnOfferTo($team, $client) ? self::CLIENT : null;
        }

        return $freelancer !== null && $team->visibility === FreelanceTeam::PUBLIC ? self::FREELANCER : null;
    }

    /**
     * The whole profile for one kind of reader (see `viewer`). The team's people get, besides, its settings and their own place.
     *
     * @return array<string, mixed>
     */
    public function present(FreelanceTeam $team, string $viewer, ?Freelancer $me = null): array
    {
        $inside = $viewer === self::MEMBER;
        $rows = Member::query()->where('team_id', $team->id)->where('status', Member::ACTIVE)->with('freelancer.media')
            ->orderByRaw("case role when 'owner' then 0 when 'pm' then 1 else 2 end")->orderBy('id')->get();
        $people = $rows->pluck('freelancer_id')->all();
        $shown = $inside ? $rows : $rows->where('show_in_profile', true);
        $stats = $this->reviews->teamStats($team);
        unset($stats['similar_projects']);
        $works = $inside ? $this->portfolio->teamItems($team) : $this->portfolio->visibleForTeam($team, $viewer === self::CLIENT);
        $base = "teams/{$team->uuid}/portfolio";

        $out = [
            'uuid' => $team->uuid,
            'name' => $team->name,
            'bio' => $team->bio,
            'logo_url' => $team->logoUrl(),
            'verified' => $team->verified_at !== null,
            'accepting_projects' => (bool) $team->accepting_projects,
            'running_projects' => Agreement::query()->where('team_id', $team->id)->where('status', Agreement::ACTIVE)->count(),
            'since' => $team->created_at?->toIso8601String(),
            'members_count' => $rows->count(),
            'members' => $shown->map(fn (Member $m) => $this->person($m, $inside))->values()->all(),
            'specialties' => $this->specialties($people),
            'skills' => $this->skills($people),
            'languages' => $this->languages($people),
            'stats' => $stats,
            'reviews' => $this->reviews->latestAboutTeam($team, self::REVIEWS_MAX),
            'portfolio' => $works->map(fn (Item $i) => $this->portfolio->present($i, $inside, $base))->values()->all(),
        ];
        if ($inside) {
            $mine = $me === null ? null : $rows->firstWhere('freelancer_id', $me->id);
            $out += [
                'status' => $team->status,
                'visibility' => $team->visibility,
                'verification_status' => $this->verificationStatus($team),
                'me' => [
                    'role' => $mine?->role,
                    'show_in_profile' => (bool) $mine?->show_in_profile,
                    'can_edit' => $me !== null && $this->abilities->can($team, $me, TeamAbilities::EDIT_TEAM),
                ],
            ];
        }

        return $out;
    }

    /**
     * What an offer shows of its team next to the name: logo, badge, rating and done projects (the full profile is `present`).
     *
     * @return array<string, mixed>
     */
    public function summary(FreelanceTeam $team): array
    {
        if (isset($this->summaries[$team->id])) {
            return $this->summaries[$team->id];
        }
        $stats = $this->reviews->teamStats($team);

        return $this->summaries[$team->id] = [
            'logo_url' => $team->logoUrl(),
            'verified' => $team->verified_at !== null,
            'accepting_projects' => (bool) $team->accepting_projects,
            'rating' => $stats['rating'],
            'reviews_count' => $stats['reviews_count'],
            'completed_projects' => $stats['completed_projects'],
        ];
    }

    // -------------------------------------------------------------- internals

    /** `verified`, `submitted` (waiting for an admin), `rejected` or `none` - FS-202b; the papers themselves are the Owners' (`teams/{uuid}/verification`). */
    private function verificationStatus(FreelanceTeam $team): string
    {
        if ($team->verified_at !== null) {
            return 'verified';
        }

        return match ($team->verificationRequests()->latest('id')->value('status')) {
            'submitted' => 'submitted',
            'rejected' => 'rejected',
            default => 'none',
        };
    }

    /** 409 freelancer_team_inactive (suspended / archived), 403 freelancer_team_ability (not an Owner). */
    private function assertEditable(FreelanceTeam $team, Freelancer $by): void
    {
        if ($team->trashed() || ! $team->isActive()) {
            throw new FreelanceException('freelancer_team_inactive', 409);
        }
        $this->abilities->assert($team, $by, TeamAbilities::EDIT_TEAM);
    }

    /**
     * A private team is open to a client it made an offer to - the owner of the project, or a person of his project team (FS-104) whose role
     * reads the offers (`offers`) - (an offer taken back before he saw it does not count).
     */
    private function madeAnOfferTo(FreelanceTeam $team, User $client): bool
    {
        $projects = ProjectMember::query()->where('user_id', $client->getKey())->where('status', ProjectMember::ACTIVE)->get()
            ->filter(fn (ProjectMember $m) => ClientTeamAbilities::allows($m, ClientTeamAbilities::OFFERS))->pluck('project_id')->all();

        return Offer::query()->where('team_id', $team->id)
            ->whereHas('project', fn ($q) => $q->where('client_id', $client->getKey())->orWhereIn('id', $projects))
            ->where(fn ($q) => $q->where('status', '!=', OfferStatus::Withdrawn->value)->orWhereNotNull('viewed_at'))
            ->exists();
    }

    /** @return array<string, mixed> */
    private function person(Member $m, bool $inside): array
    {
        $f = $m->freelancer;
        if ($inside) {
            return ['freelancer' => $f?->uuid, 'name' => $f?->name, 'role' => $m->role, 'show_in_profile' => (bool) $m->show_in_profile];
        }

        // D10: outside the team, a first name only.
        return [
            'name' => strtok((string) $f?->name, ' ') ?: (string) $f?->name,
            'role' => $m->role,
            'headline' => $f?->headline,
            'avatar_url' => $f?->getFirstMediaUrl('avatar') ?: null,
        ];
    }

    /**
     * The specialties the team's people work in, the most shared first.
     *
     * @param  list<int>  $people
     * @return list<array{id: int, name: ?string}>
     */
    private function specialties(array $people): array
    {
        $ids = DB::table('freelancer_specialties')->whereIn('freelancer_id', $people)->groupBy('freelance_specialty_id')
            ->orderByRaw('count(*) desc')->orderBy('freelance_specialty_id')->limit(self::SPECIALTIES_MAX)->pluck('freelance_specialty_id')->all();
        $found = FreelanceSpecialty::query()->whereIn('id', $ids)->where('status', true)->with('translations')->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $found->get($id))->filter()
            ->map(fn (FreelanceSpecialty $s) => ['id' => $s->id, 'name' => $s->translatedName()])->values()->all();
    }

    /**
     * @param  list<int>  $people
     * @return list<array{id: int, name: ?string}>
     */
    private function skills(array $people): array
    {
        $ids = DB::table('freelancer_skills')->whereIn('freelancer_id', $people)->groupBy('freelance_skill_id')
            ->orderByRaw('count(*) desc')->orderBy('freelance_skill_id')->limit(self::SKILLS_MAX)->pluck('freelance_skill_id')->all();
        $found = FreelanceSkill::query()->whereIn('id', $ids)->where('status', FreelanceSkill::ACTIVE)->with('translations')->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $found->get($id))->filter()
            ->map(fn (FreelanceSkill $s) => ['id' => $s->id, 'name' => $s->translatedName()])->values()->all();
    }

    /**
     * @param  list<int>  $people
     * @return list<string>
     */
    private function languages(array $people): array
    {
        return Freelancer::query()->whereIn('id', $people)->pluck('contact_languages')
            ->flatMap(fn ($list) => is_array($list) ? $list : (is_string($list) ? (array) (json_decode($list, true) ?: []) : []))->filter(fn ($l) => is_string($l) && $l !== '')
            ->unique()->sort()->values()->all();
    }
}
