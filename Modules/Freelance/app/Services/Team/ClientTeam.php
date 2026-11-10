<?php

namespace Modules\Freelance\Services\Team;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceProjectStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceProjectMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\User\Models\User;

/**
 * FS-104: the owner of a project brings people into it — a regular Dorr user each, found by the phone of his account, with one role
 * (docs/modules/freelance/PERMISSIONS.md). The invited person accepts or declines; the owner changes a role or takes someone out at once;
 * a member may leave. The owner stays the legal side: contract, payment and disputes are his.
 */
class ClientTeam
{
    public const INVITE_DAYS = 7;

    public const MAX_DELEGATE_DAYS = 90;

    /**
     * The client events a member also receives, and the ability he needs for each (events carrying an amount need `money`).
     *
     * @var array<string, string>
     */
    public const FAN_OUT = [
        'agreement.active' => ClientTeamAbilities::VIEW,
        'milestone.delivered' => ClientTeamAbilities::VIEW,
        'milestone.review_due' => ClientTeamAbilities::VIEW,
        'milestone.auto_approved' => ClientTeamAbilities::VIEW,
        'milestone.cancel_requested' => ClientTeamAbilities::VIEW,
        'milestone.cancelled' => ClientTeamAbilities::MONEY,
        'milestone.called_off' => ClientTeamAbilities::VIEW,
        'project.completed' => ClientTeamAbilities::VIEW,
        'dispute.opened' => ClientTeamAbilities::VIEW,
        'dispute.decided' => ClientTeamAbilities::VIEW,
        'dispute.resolved' => ClientTeamAbilities::VIEW,
        'change.requested' => ClientTeamAbilities::VIEW,
        'change.accepted' => ClientTeamAbilities::VIEW,
        'change.rejected' => ClientTeamAbilities::VIEW,
        'change.reminder' => ClientTeamAbilities::VIEW,
        'health.at_risk' => ClientTeamAbilities::VIEW,
        'health.delayed' => ClientTeamAbilities::VIEW,
        'health.blocked' => ClientTeamAbilities::VIEW,
        'report.received' => ClientTeamAbilities::VIEW,
        'report.late' => ClientTeamAbilities::VIEW,
        // FS-150a: a task the freelancer gave to the client's side reaches the people who can work on tasks.
        'workroom.task_assigned' => ClientTeamAbilities::WORKROOM,
        // FS-150b: a file or a note the freelancer put in the Workroom reaches the people who can answer it.
        'workroom.file_shared' => ClientTeamAbilities::WORKROOM,
        'workroom.comment_added' => ClientTeamAbilities::WORKROOM,
        // FS-150c: a decision the freelancer proposed (to answer) or answered (to know).
        'workroom.decision_proposed' => ClientTeamAbilities::WORKROOM,
        'workroom.decision_agreed' => ClientTeamAbilities::WORKROOM,
        'workroom.decision_declined' => ClientTeamAbilities::WORKROOM,
    ];

    public function __construct(private readonly FreelanceNotifier $notifier) {}

    public static function maxMembers(): int
    {
        return max(1, (int) config('freelance.client_team.max_members', 10));
    }

    /** @return list<Member> the owner's view: everyone asked, newest first (the ones that ended are left out) */
    public function members(Project $project): array
    {
        return Member::query()->where('project_id', $project->id)->whereIn('status', [Member::INVITED, Member::ACTIVE])
            ->with('user')->latest('id')->get()->all();
    }

    /** @param  array{phone: string, role: string, max_amount_minor?: ?int, days?: ?int}  $data */
    public function invite(Project $project, User $owner, array $data): Member
    {
        $this->assertOwner($project, $owner);
        if (in_array($project->status, [Status::Cancelled, Status::Rejected], true)) {
            throw new FreelanceException('team_project_ended', 409);
        }
        $user = $this->findUser((string) $data['phone']);
        if ($user === null) {
            throw new FreelanceException('team_user_not_found', 422);
        }
        if ((int) $user->id === (int) $owner->id) {
            throw new FreelanceException('team_self', 422);
        }
        if ($this->bidsOn($project, $user)) {
            throw new FreelanceException('team_conflict', 422);
        }

        $member = DB::transaction(function () use ($project, $owner, $user, $data) {
            Project::query()->whereKey($project->id)->lockForUpdate()->first();
            $existing = Member::query()->where('project_id', $project->id)->where('user_id', $user->id)->first();
            if ($existing !== null && in_array($existing->status, [Member::INVITED, Member::ACTIVE], true) && ! $this->invitationOver($existing)) {
                throw new FreelanceException('team_already', 409);
            }
            $taken = Member::query()->where('project_id', $project->id)->whereIn('status', [Member::INVITED, Member::ACTIVE])
                ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->id))->get()->reject(fn (Member $m) => $this->invitationOver($m))->count();
            if ($taken >= self::maxMembers()) {
                throw new FreelanceException('team_full', 422, ['max' => self::maxMembers()], ['max' => self::maxMembers()]);
            }
            $fields = ['role' => $data['role'], 'status' => Member::INVITED, 'invited_by' => $owner->id, 'invited_at' => now(),
                'responded_at' => null, 'ended_at' => null] + $this->limits($data['role'], $data);

            return $existing === null
                ? Member::query()->create($fields + ['project_id' => $project->id, 'user_id' => $user->id])
                : tap($existing)->update($fields);
        });

        $this->notifier->notify($user, 'team.invited', ['project' => (string) $project->title, 'name' => (string) $owner->name],
            ['project' => $project->uuid, 'invitation' => $member->uuid]);

        return $member->load('user');
    }

    /** @param  array{role: string, max_amount_minor?: ?int, days?: ?int}  $data a new role (and limits) — in force from the next request */
    public function update(Project $project, User $owner, string $uuid, array $data): Member
    {
        $this->assertOwner($project, $owner);
        $member = $this->own($project, $uuid);
        $before = $member->role;
        $member->update(['role' => $data['role']] + $this->limits($data['role'], $data));
        if ($member->status === Member::ACTIVE && $before !== $member->role) {
            $this->notifier->notify($member->user, 'team.role_changed', ['project' => (string) $project->title], ['project' => $project->uuid]);
        }

        return $member->load('user');
    }

    public function remove(Project $project, User $owner, string $uuid): void
    {
        $this->assertOwner($project, $owner);
        $member = $this->own($project, $uuid);
        $wasActive = $member->status === Member::ACTIVE;
        $member->update(['status' => 'removed', 'ended_at' => now()]);
        if ($wasActive) {
            $this->notifier->notify($member->user, 'team.removed', ['project' => (string) $project->title], ['project' => $project->uuid]);
        }
    }

    /** @return list<Member> the invitations waiting for me */
    public function invitations(User $me): array
    {
        return Member::query()->where('user_id', $me->id)->where('status', Member::INVITED)->with(['project', 'project.client'])
            ->latest('id')->get()->reject(fn (Member $m) => $this->invitationOver($m) || $m->project === null)->values()->all();
    }

    /** @param  'accept'|'decline'  $answer */
    public function respond(User $me, string $uuid, string $answer): Member
    {
        $member = Member::query()->where('uuid', $uuid)->where('user_id', $me->id)->with(['project', 'project.client'])->firstOrFail();
        if ($member->status !== Member::INVITED || $this->invitationOver($member)) {
            throw new FreelanceException('team_invitation_closed', 409);
        }
        $member->update(['status' => $answer === 'accept' ? Member::ACTIVE : 'declined', 'responded_at' => now()]);
        $project = $member->project;
        $this->notifier->notify($project->client, $answer === 'accept' ? 'team.joined' : 'team.declined',
            ['project' => (string) $project->title, 'name' => (string) $me->name], ['project' => $project->uuid]);

        return $member;
    }

    public function leave(User $me, string $projectUuid): void
    {
        $member = Member::query()->where('user_id', $me->id)->where('status', Member::ACTIVE)
            ->whereHas('project', fn ($q) => $q->where('uuid', $projectUuid))->with(['project', 'project.client'])->firstOrFail();
        $member->update(['status' => 'left', 'ended_at' => now()]);
        $this->notifier->notify($member->project->client, 'team.left', ['project' => (string) $member->project->title, 'name' => (string) $me->name],
            ['project' => $member->project->uuid]);
    }

    /** @return LengthAwarePaginator<Member> the projects I am in, newest first */
    public function shared(User $me, int $perPage): LengthAwarePaginator
    {
        return Member::query()->where('user_id', $me->id)->where('status', Member::ACTIVE)
            ->whereHas('project')->with(['project.specialty.translations', 'project.skills.translations', 'project.media', 'project.client'])
            ->latest('id')->paginate($perPage);
    }

    /** My active membership of this project, if any. */
    public function activeMember(User $user, Project $project): ?Member
    {
        return Member::query()->where('project_id', $project->id)->where('user_id', $user->id)->where('status', Member::ACTIVE)->first();
    }

    /** @return Collection<int, User> the members who hear about this client event of the project */
    public function recipients(string $projectUuid, int $ownerId, string $event): Collection
    {
        $ability = self::FAN_OUT[$event] ?? null;
        if ($ability === null) {
            return collect();
        }

        return Member::query()->where('status', Member::ACTIVE)
            ->whereHas('project', fn ($q) => $q->where('uuid', $projectUuid)->where('client_id', $ownerId))
            ->with('user')->get()
            ->filter(fn (Member $m) => $m->user !== null && ClientTeamAbilities::allows($m, $ability))
            ->map(fn (Member $m) => $m->user)->values();
    }

    /** @return array<string, mixed> what the apps need to draw the page for this viewer (null member = the owner) */
    public function access(Project $project, ?Member $member): array
    {
        if ($member === null) {
            return ['role' => 'owner', 'abilities' => ClientTeamAbilities::all(), 'money' => true, 'owner' => null, 'max_amount_minor' => null,
                'ends_at' => null, 'delegation_over' => false];
        }
        $project->loadMissing('client');

        return [
            'role' => $member->role,
            'abilities' => ClientTeamAbilities::of($member),
            'money' => ClientTeamAbilities::allows($member, ClientTeamAbilities::MONEY),
            'owner' => ['name' => (string) $project->client?->name],
            'max_amount_minor' => $member->max_amount_minor,
            'ends_at' => $member->ends_at?->toIso8601String(),
            'delegation_over' => $member->delegationOver(),
        ];
    }

    /** @return array<string, mixed> one person of the team, for the owner (the phone is his own account's, shown masked) */
    public function present(Member $m): array
    {
        $expired = $this->invitationOver($m);

        return [
            'uuid' => $m->uuid,
            'name' => (string) $m->user?->name,
            'phone' => $this->maskPhone((string) $m->user?->phone),
            'role' => $m->role,
            'status' => $expired ? 'expired' : $m->status,
            'max_amount_minor' => $m->max_amount_minor,
            'ends_at' => $m->ends_at?->toIso8601String(),
            'delegation_over' => $m->delegationOver(),
            'invited_at' => $m->invited_at?->toIso8601String(),
            'responded_at' => $m->responded_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> an invitation, for the person invited */
    public function presentInvitation(Member $m): array
    {
        return [
            'uuid' => $m->uuid,
            'role' => $m->role,
            'project' => ['uuid' => $m->project->uuid, 'title' => (string) $m->project->title],
            'owner' => ['name' => (string) $m->project->client?->name],
            'max_amount_minor' => $m->max_amount_minor,
            'currency' => $m->project->currency_code,
            'ends_at' => $m->ends_at?->toIso8601String(),
            'invited_at' => $m->invited_at?->toIso8601String(),
            'expires_at' => $m->invited_at?->copy()->addDays(self::INVITE_DAYS)->toIso8601String(),
        ];
    }

    public function invitationOver(Member $m): bool
    {
        return $m->status === Member::INVITED && $m->invited_at !== null && $m->invited_at->copy()->addDays(self::INVITE_DAYS)->isPast();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{max_amount_minor: ?int, ends_at: ?\Illuminate\Support\Carbon}
     */
    private function limits(string $role, array $data): array
    {
        if ($role !== 'delegate') {
            return ['max_amount_minor' => null, 'ends_at' => null];
        }
        $max = (int) ($data['max_amount_minor'] ?? 0);
        $days = (int) ($data['days'] ?? 0);
        if ($max < 1 || $days < 1 || $days > self::MAX_DELEGATE_DAYS) {
            throw new FreelanceException('team_delegate_limits', 422, ['days' => self::MAX_DELEGATE_DAYS], ['max_days' => self::MAX_DELEGATE_DAYS]);
        }

        return ['max_amount_minor' => $max, 'ends_at' => now()->addDays($days)->endOfDay()];
    }

    private function own(Project $project, string $uuid): Member
    {
        return Member::query()->where('project_id', $project->id)->where('uuid', $uuid)->whereIn('status', [Member::INVITED, Member::ACTIVE])
            ->with('user')->firstOrFail();
    }

    private function assertOwner(Project $project, User $owner): void
    {
        abort_unless((int) $project->client_id === (int) $owner->id, 404);
    }

    /** A Dorr account by its phone (as typed, with or without the +), verified and not blocked. */
    private function findUser(string $phone): ?User
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 6) {
            return null;
        }

        return User::query()->whereIn('phone', ['+'.$digits, $digits])->whereNotNull('phone_verified_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))->first();
    }

    /** The same person behind a freelancer who made an offer on this project: he can not also sit on the client's side. */
    private function bidsOn(Project $project, User $user): bool
    {
        $ids = Freelancer::query()->where('phone', (string) $user->phone)->pluck('id');

        return $ids->isNotEmpty() && FreelanceOffer::query()->where('project_id', $project->id)->whereIn('freelancer_id', $ids)->exists();
    }

    /** A freelancer sitting on the client's side of this project (an invitation not over, or a member) by the same phone. */
    public function sitsOnTeam(Project $project, string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return false;
        }

        return Member::query()->where('project_id', $project->id)->whereIn('status', [Member::INVITED, Member::ACTIVE])
            ->whereHas('user', fn ($q) => $q->whereIn('phone', ['+'.$digits, $digits]))->get()
            ->contains(fn (Member $m) => ! $this->invitationOver($m));
    }

    private function maskPhone(string $phone): string
    {
        $len = mb_strlen($phone);

        return $len <= 4 ? $phone : mb_substr($phone, 0, max(0, $len - 7)).str_repeat('•', min(3, $len - 4)).mb_substr($phone, -4);
    }
}
