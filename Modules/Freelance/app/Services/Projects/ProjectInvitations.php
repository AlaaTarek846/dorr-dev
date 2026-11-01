<?php

namespace Modules\Freelance\Services\Projects;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceProjectStatus as Status;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceProjectInvitation as Invitation;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Verification\FreelancerGate;
use Modules\User\Models\User;

/**
 * Private projects and invitations (FL-205). A private project is not in the freelancers' list: the client invites freelancers of the
 * project's country (up to the policy `invitations.max_per_project`) and only they can open it and offer — the client then chooses one
 * of the offers like on any project. A freelancer answers yes or no (a verified account is needed to answer; offering also says yes).
 * When nobody has accepted, the client can make the project public: the open invitations end and anyone can offer.
 */
class ProjectInvitations
{
    public function __construct(
        private readonly ProjectOffersWindow $window,
        private readonly ProjectStateMachine $machine,
        private readonly FreelancePolicies $policies,
        private readonly FreelancerGate $gate,
        private readonly FreelanceNotifier $notifier,
        private readonly ProjectMatching $matching,
    ) {}

    // ----------------------------------------------------------------- the client

    /** @return array<string, mixed> the numbers and what the client may do now, for the project page */
    public function summary(Project $project): array
    {
        $counts = Invitation::query()->where('project_id', $project->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $live = (int) ($counts[Invitation::SENT] ?? 0) + (int) ($counts[Invitation::ACCEPTED] ?? 0);
        $private = $project->visibility === 'private' && $project->status === Status::Published;

        return [
            'sent' => (int) ($counts[Invitation::SENT] ?? 0),
            'accepted' => (int) ($counts[Invitation::ACCEPTED] ?? 0),
            'declined' => (int) ($counts[Invitation::DECLINED] ?? 0),
            'max' => $this->max($project),
            'can_invite' => $private && $this->window->accepting($project) && $live < $this->max($project),
            // Nobody has said yes (an offer says yes too): the project may open to everyone.
            'can_make_public' => $private && (int) ($counts[Invitation::ACCEPTED] ?? 0) === 0,
        ];
    }

    /** @return list<array<string, mixed>> the invitations of the project, newest first */
    public function list(Project $project): array
    {
        $offers = Offer::query()->where('project_id', $project->id)->where('status', '!=', 'withdrawn')->pluck('uuid', 'freelancer_id');

        return Invitation::query()->where('project_id', $project->id)->with('freelancer')->orderByDesc('id')->get()
            ->map(fn (Invitation $i) => [
                'uuid' => $i->uuid,
                'status' => $i->status,
                'freelancer' => $i->freelancer === null ? null : $this->card($i->freelancer, $project),
                'offer' => $offers[$i->freelancer_id] ?? null,
                'invited_at' => $i->invited_at?->toIso8601String(),
                'responded_at' => $i->responded_at?->toIso8601String(),
                'can_withdraw' => $i->status === Invitation::SENT,
            ])->values()->all();
    }

    /**
     * Freelancers the client may invite: verified, of the project's country, not invited yet (or whose invitation was taken back or ran
     * out). Without `$all` and a search, those of the project's specialty.
     *
     * @return list<array<string, mixed>>
     */
    public function invitable(Project $project, ?string $q, bool $all): array
    {
        // Someone who already sent an offer on it needs no invitation.
        $taken = Invitation::query()->where('project_id', $project->id)->whereIn('status', [Invitation::SENT, Invitation::ACCEPTED, Invitation::DECLINED])->pluck('freelancer_id')
            ->merge(Offer::query()->where('project_id', $project->id)->pluck('freelancer_id'))->unique()->values();

        return Freelancer::query()
            ->where('status', FreelancerStatus::Verified->value)->where('country_id', $project->country_id)->whereNotIn('id', $taken)
            ->when($q !== null && trim($q) !== '', fn (Builder $b) => $b->where('name', 'like', '%'.addcslashes(trim($q), '%_\\').'%'))
            ->when(! $all && ($q === null || trim($q) === '') && $project->freelance_specialty_id !== null,
                fn (Builder $b) => $b->whereHas('specialties', fn ($s) => $s->where('freelance_specialties.id', $project->freelance_specialty_id)))
            ->with(['specialties.translations'])->orderBy('name')->limit(30)->get()
            ->map(fn (Freelancer $f) => $this->card($f, $project))->values()->all();
    }

    /**
     * @param  list<string>  $uuids  freelancers (their uuids)
     * @return array{invited: int, skipped: list<array{uuid: string, reason: string}>}
     */
    public function invite(Project $project, User $client, array $uuids): array
    {
        abort_unless((int) $project->client_id === (int) $client->id, 404);
        app(\Modules\Freelance\Services\Moderation\Sanctions::class)->assertAllowed($client, 'work');
        $invited = [];
        $skipped = [];
        DB::transaction(function () use ($project, $client, $uuids, &$invited, &$skipped) {
            $p = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->assertInvitable($p);
            $max = $this->max($p);
            $live = Invitation::query()->where('project_id', $p->id)->whereIn('status', Invitation::LIVE)->count();
            $found = Freelancer::query()->whereIn('uuid', array_unique($uuids))->get()->keyBy('uuid');
            $offered = Offer::query()->where('project_id', $p->id)->pluck('freelancer_id')->all();
            foreach (array_unique($uuids) as $uuid) {
                $f = $found[$uuid] ?? null;
                if ($f === null || (int) $f->country_id !== (int) $p->country_id || $f->status !== FreelancerStatus::Verified) {
                    $skipped[] = ['uuid' => $uuid, 'reason' => 'not_found'];

                    continue;
                }
                if (in_array($f->id, $offered, true)) {
                    $skipped[] = ['uuid' => $uuid, 'reason' => 'already_offered'];

                    continue;
                }
                $row = Invitation::query()->where('project_id', $p->id)->where('freelancer_id', $f->id)->first();
                if ($row !== null && in_array($row->status, Invitation::LIVE, true)) {
                    $skipped[] = ['uuid' => $uuid, 'reason' => 'already_invited'];

                    continue;
                }
                if ($row !== null && $row->status === Invitation::DECLINED) {
                    $skipped[] = ['uuid' => $uuid, 'reason' => 'declined'];

                    continue;
                }
                if ($live + count($invited) >= $max) {
                    throw new FreelanceException('invitations_limit', 422, ['max' => $max], ['max' => $max]);
                }
                $fields = ['status' => Invitation::SENT, 'invited_at' => now(), 'responded_at' => null];
                $row === null
                    ? Invitation::query()->create(['project_id' => $p->id, 'freelancer_id' => $f->id, 'client_id' => $client->id] + $fields)
                    : $row->forceFill($fields)->save();
                $invited[] = $f;
            }
            if ($invited !== []) {
                $this->machine->record($p, 'project.invited', 'client', $client->id, ['count' => count($invited)]);
            }
        });
        foreach ($invited as $f) {
            $this->notifier->notify($f, 'invitation.received', ['project' => (string) $project->title], ['project' => $project->uuid]);
        }

        return ['invited' => count($invited), 'skipped' => $skipped];
    }

    /** The client takes an invitation back while it waits for an answer. */
    public function withdraw(Project $project, User $client, string $uuid): void
    {
        abort_unless((int) $project->client_id === (int) $client->id, 404);
        DB::transaction(function () use ($project, $uuid) {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $i = Invitation::query()->where('project_id', $project->id)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($i->status !== Invitation::SENT) {
                throw new FreelanceException('invitation_not_open', 409);
            }
            $i->forceFill(['status' => Invitation::WITHDRAWN, 'responded_at' => now()])->save();
        });
    }

    /** Nobody accepted: the project opens to everyone. The open invitations end; if the offers had closed, a new window starts. */
    public function makePublic(Project $project, User $client): Project
    {
        abort_unless((int) $project->client_id === (int) $client->id, 404);

        $public = DB::transaction(function () use ($project, $client) {
            $p = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($p->visibility !== 'private' || $p->status !== Status::Published) {
                throw new FreelanceException('invitation_project_public', 422);
            }
            if (Invitation::query()->where('project_id', $p->id)->where('status', Invitation::ACCEPTED)->exists()) {
                throw new FreelanceException('invitation_accepted_exists', 409);
            }
            $reopened = ! $this->window->accepting($p);
            $changes = ['visibility' => 'public'];
            if ($reopened) {
                $changes += ['offers_deadline_at' => $this->window->deadlineFor($p), 'offers_closed_at' => null, 'offers_close_reason' => null];
            }
            $p->forceFill($changes)->save();
            Invitation::query()->where('project_id', $p->id)->where('status', Invitation::SENT)->update(['status' => Invitation::EXPIRED, 'responded_at' => now()]);
            $this->machine->record($p, 'project.made_public', 'client', $client->id, ['window_reopened' => $reopened]);

            return $p;
        });
        // FL-208: now that anyone may offer, the freelancers it suits hear of it.
        NotificationCenter::later(fn () => $this->matching->alert($public));

        return $public;
    }

    // -------------------------------------------------------------- the freelancer

    /** Does this freelancer hold a live invitation to the project (so they may see it and offer)? */
    public function hasLive(Freelancer $freelancer, Project $project): bool
    {
        return Invitation::query()->where('project_id', $project->id)->where('freelancer_id', $freelancer->id)->whereIn('status', Invitation::LIVE)->exists();
    }

    /** @return array{uuid: string, status: string}|null their invitation to the project, for the project page */
    public function mineOn(Freelancer $freelancer, Project $project): ?array
    {
        $i = Invitation::query()->where('project_id', $project->id)->where('freelancer_id', $freelancer->id)->first();

        return $i === null ? null : ['uuid' => $i->uuid, 'status' => $i->status];
    }

    /** Sending an offer says yes to the invitation (inside the offer's transaction). */
    public function acceptedByOffer(Freelancer $freelancer, Project $project): void
    {
        Invitation::query()->where('project_id', $project->id)->where('freelancer_id', $freelancer->id)->where('status', Invitation::SENT)
            ->update(['status' => Invitation::ACCEPTED, 'responded_at' => now()]);
    }

    /** @return list<array<string, mixed>> my invitations (not the taken-back ones), newest first — the first page */
    public function forFreelancer(Freelancer $freelancer): array
    {
        return $this->pageFor($freelancer, 50)->getCollection()->all();
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> one page of my invitations (not the taken-back ones, or only those with `$status`), newest first */
    public function pageFor(Freelancer $freelancer, int $perPage, ?string $status = null): LengthAwarePaginator
    {
        $page = Invitation::query()->where('freelancer_id', $freelancer->id)->whereIn('status', $status !== null ? [$status] : [Invitation::SENT, Invitation::ACCEPTED, Invitation::DECLINED])
            ->whereHas('project')->with(['project.specialty.translations', 'project.skills.translations'])->orderByDesc('id')->paginate($perPage);
        $offered = app(FreelancerProjects::class)->offeredIds($freelancer, $page->getCollection()->pluck('project')->filter());

        return $page->setCollection($page->getCollection()->map(function (Invitation $i) use ($offered) {
            $open = $i->project->status === Status::Published && $this->window->accepting($i->project);
            $live = in_array($i->status, Invitation::LIVE, true) && $open;

            return [
                'uuid' => $i->uuid,
                'status' => $i->status,
                'open' => $open,
                'project' => app(FreelancerProjects::class)->present($i->project, false, false),
                'offered' => isset($offered[$i->project_id]),
                'invited_at' => $i->invited_at?->toIso8601String(),
                'can' => ['accept' => $i->status === Invitation::SENT && $open, 'decline' => $i->status === Invitation::SENT && $open, 'offer' => $live && ! isset($offered[$i->project_id])],
            ];
        })->values());
    }

    /** Yes or no. A verified account is needed (FL-110); saying yes also needs a freelancer who takes work now. */
    public function respond(Freelancer $freelancer, string $uuid, bool $accept): Invitation
    {
        $first = Invitation::query()->where('uuid', $uuid)->where('freelancer_id', $freelancer->id)->firstOrFail();
        if ($accept) {
            $this->gate->assertCanOffer($freelancer);
        } else {
            $this->gate->assertCanApply($freelancer);
        }
        $noneLeft = false;
        $invitation = DB::transaction(function () use ($first, $accept, &$noneLeft) {
            $p = Project::query()->whereKey($first->project_id)->lockForUpdate()->firstOrFail();
            $i = Invitation::query()->whereKey($first->id)->lockForUpdate()->firstOrFail();
            if ($accept && $i->status === Invitation::ACCEPTED) {
                return $i;
            }
            if ($i->status !== Invitation::SENT) {
                throw new FreelanceException('invitation_not_open', 409);
            }
            if ($p->status !== Status::Published || ! $this->window->accepting($p)) {
                throw new FreelanceException('invitation_closed', 409);
            }
            $i->forceFill(['status' => $accept ? Invitation::ACCEPTED : Invitation::DECLINED, 'responded_at' => now()])->save();
            // The last one said no: the client is told, to open the project to everyone if they want.
            $noneLeft = ! $accept && ! Invitation::query()->where('project_id', $p->id)->whereIn('status', Invitation::LIVE)->exists();

            return $i;
        });
        $project = $invitation->project()->first();
        $client = $project?->client;
        if ($client !== null && $invitation->wasChanged('status')) {
            $this->notifier->notify($client, $accept ? 'invitation.accepted' : 'invitation.declined', ['project' => (string) $project->title, 'name' => (string) $freelancer->name], ['project' => $project->uuid]);
            if ($noneLeft) {
                $this->notifier->notify($client, 'invitation.none_left', ['project' => (string) $project->title], ['project' => $project->uuid]);
            }
        }

        return $invitation;
    }

    // ---------------------------------------------------------------------- inside

    private function assertInvitable(Project $p): void
    {
        if ($p->visibility !== 'private') {
            throw new FreelanceException('invitation_project_public', 422);
        }
        if ($p->status !== Status::Published) {
            throw new FreelanceException('invitation_project_not_published', 422);
        }
        $this->window->assertAccepting($p);
    }

    private function max(Project $project): int
    {
        return max(1, (int) ($this->policies->for($project->country_id, $project->freelance_specialty_id)->get('invitations.max_per_project') ?? 10));
    }

    /** @return array<string, mixed> a freelancer as the client picks them to invite */
    private function card(Freelancer $f, Project $project): array
    {
        $f->loadMissing('specialties.translations');
        $stats = app(FreelanceReviews::class)->stats($f, $project->freelance_specialty_id);

        return [
            'uuid' => $f->uuid,
            'name' => $f->name,
            'headline' => $f->headline,
            'avatar_url' => $f->getFirstMediaUrl('avatar') ?: null,
            'specialties' => $f->specialties->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName()])->values()->all(),
        ] + array_intersect_key($stats, array_flip(['rating', 'reviews_count', 'completed_projects']));
    }
}
