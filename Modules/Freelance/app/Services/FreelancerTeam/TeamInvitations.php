<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use App\Services\General\AuditLogger;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamInvitation as Invitation;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;

/**
 * FS-203: how people come into a freelancers' team. A person with the right to invite (the Owner, or a PM the Owner switched `invite_members`
 * on for) invites one freelancer by phone - from any country, the team's country does not matter - or makes a link anybody can use once.
 * The invited person answers in the app; accepting goes through `FreelancerTeams::join`, so every rule of the team (verified, the person's and
 * the team's limits, an active team) is checked at that moment and a refusal leaves the invitation open. An invitation lasts 7 days (like the
 * client team's), and a lapsed one is closed when somebody touches it - there is no job for it. Only the Owner invites somebody as a PM.
 *
 * Every invitation, answer, cancellation, leaving and removal is on the audit log (`freelance.team.*`).
 */
class TeamInvitations
{
    public const DAYS = 7;

    public function __construct(
        private readonly FreelancerTeams $teams,
        private readonly TeamAbilities $abilities,
        private readonly NotificationCenter $notifications,
        private readonly AuditLogger $audit,
    ) {}

    // -------------------------------------------------------------- inviting

    /**
     * 403 freelancer_team_ability / freelancer_team_forbidden (a PM inviting a PM), 409 freelancer_team_inactive, 422 freelancer_team_role_invalid,
     * 422 freelancer_team_invite_not_found (no such freelancer), 422 freelancer_team_invite_self, 409 freelancer_team_already_member,
     * 409 freelancer_team_invite_open (this person already has one open).
     *
     * @param  ?string  $phone  null = an invitation by link
     */
    public function invite(FreelanceTeam $team, Freelancer $by, ?string $phone, string $role): Invitation
    {
        if (! in_array($role, [Member::PM, Member::MEMBER], true)) {
            throw new FreelanceException('freelancer_team_role_invalid', 422);
        }
        if (! $team->isActive() || $team->trashed()) {
            throw new FreelanceException('freelancer_team_inactive', 409);
        }
        $this->abilities->assert($team, $by, TeamAbilities::INVITE_MEMBERS);
        if ($role === Member::PM && ! $this->teams->isOwner($team, $by)) {
            throw new FreelanceException('freelancer_team_forbidden', 403);
        }
        $target = null;
        if ($phone !== null) {
            $target = $this->find($phone);
            if ($target === null) {
                throw new FreelanceException('freelancer_team_invite_not_found', 422);
            }
            if ($target->id === $by->id) {
                throw new FreelanceException('freelancer_team_invite_self', 422);
            }
        }

        $invitation = DB::transaction(function () use ($team, $by, $target, $role): Invitation {
            $locked = FreelanceTeam::withTrashed()->lockForUpdate()->findOrFail($team->id);
            if ($locked->trashed() || ! $locked->isActive()) {
                throw new FreelanceException('freelancer_team_inactive', 409);
            }
            if ($target !== null) {
                if ($this->teams->isMember($locked, $target)) {
                    throw new FreelanceException('freelancer_team_already_member', 409);
                }
                $open = Invitation::query()->where('team_id', $locked->id)->where('freelancer_id', $target->id)->where('status', Invitation::PENDING)->lockForUpdate()->get();
                if ($open->contains(fn (Invitation $i) => ! $this->lapsed($i))) {
                    throw new FreelanceException('freelancer_team_invite_open', 409);
                }
                $open->each(fn (Invitation $i) => $this->close($i, Invitation::EXPIRED));
            }

            return Invitation::query()->create([
                'team_id' => $locked->id, 'freelancer_id' => $target?->id, 'token' => $target === null ? Str::random(40) : null, 'role' => $role,
                'invited_by_freelancer_id' => $by->id, 'status' => Invitation::PENDING, 'expires_at' => now()->addDays(self::DAYS),
            ]);
        });

        $this->record('freelance.team.invited', $invitation, null, ['team' => $team->uuid, 'role' => $role, 'by' => $by->id, 'invited' => $target?->id, 'link' => $target === null]);
        if ($target !== null) {
            $this->notifications->send($target, 'freelance.team_invited', 'freelance_team_invited_title', 'freelance_team_invited_body',
                ['name' => $this->first($by), 'team' => $team->name], ['type' => 'freelance_team_invited', 'team' => $team->uuid, 'invitation' => $invitation->uuid]);
        }

        return $invitation->load(['team:id,uuid,name', 'freelancer:id,uuid,name']);
    }

    /** Takes back an open invitation: the Owner, or the person who sent it. 404 not found, 403 freelancer_team_forbidden, 409 freelancer_team_invitation_closed. */
    public function cancel(FreelanceTeam $team, Freelancer $by, string $uuid): Invitation
    {
        return DB::transaction(function () use ($team, $by, $uuid): Invitation {
            $i = Invitation::query()->where('team_id', $team->id)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if (! $this->teams->isOwner($team, $by) && (int) $i->invited_by_freelancer_id !== (int) $by->id) {
                throw new FreelanceException('freelancer_team_forbidden', 403);
            }
            $this->assertOpen($i);
            $this->close($i, Invitation::CANCELLED);
            $this->record('freelance.team.invitation_cancelled', $i, null, ['team' => $team->uuid, 'by' => $by->id]);

            return $i;
        });
    }

    // -------------------------------------------------------------- answering

    /** @return Collection<int, Invitation> the invitations waiting for me (a lapsed one, or of a team that is not active, is left out) */
    public function mine(Freelancer $me): Collection
    {
        return Invitation::query()->where('freelancer_id', $me->id)->where('status', Invitation::PENDING)->with(['team', 'inviter:id,name'])->latest('id')->get()
            ->reject(fn (Invitation $i) => $this->lapsed($i) || $i->team === null || ! $i->team->isActive())->values();
    }

    /** @return Collection<int, Invitation> a team's open invitations, for the people who may invite */
    public function open(FreelanceTeam $team): Collection
    {
        return Invitation::query()->where('team_id', $team->id)->where('status', Invitation::PENDING)->with(['freelancer:id,uuid,name', 'inviter:id,name'])->latest('id')->get()
            ->reject(fn (Invitation $i) => $this->lapsed($i))->values();
    }

    /**
     * Accepts an invitation addressed to me. Every rule of `FreelancerTeams::join` applies now (403 freelance_verification_required,
     * 422 freelancer_team_limit_joined / freelancer_team_limit_members, 409 freelancer_team_inactive ...) and, when one refuses, the invitation
     * stays open. 404 not mine, 409 freelancer_team_invitation_closed (answered, taken back or past its days).
     */
    public function accept(Freelancer $me, string $uuid): Invitation
    {
        return $this->answer($me, Invitation::query()->where('uuid', $uuid)->where('freelancer_id', $me->id), true);
    }

    public function decline(Freelancer $me, string $uuid): Invitation
    {
        return $this->answer($me, Invitation::query()->where('uuid', $uuid)->where('freelancer_id', $me->id), false);
    }

    /** What a link leads to, before the person accepts it (404 unknown, 409 freelancer_team_invitation_closed). */
    public function preview(string $token): Invitation
    {
        $i = $this->byToken($token)->with(['team:id,uuid,name,status', 'inviter:id,name'])->firstOrFail();
        $this->assertOpen($i);

        return $i;
    }

    /** A link is used once: the first freelancer who accepts it is in, and nobody after him. */
    public function acceptLink(Freelancer $me, string $token): Invitation
    {
        return $this->answer($me, $this->byToken($token), true);
    }

    // -------------------------------------------------------------- leaving

    /** The person leaves at once (D5): FreelancerTeams::leave, plus the log and a word to the Owners. */
    public function leave(FreelanceTeam $team, Freelancer $me, ?string $reason): Member
    {
        $row = $this->teams->leave($team, $me, $reason);
        app(TeamAssignments::class)->release($team, $me); // D5: what he held lets go at once
        $this->record('freelance.team.member_left', $row, null, ['team' => $team->uuid, 'freelancer' => $me->id, 'reason' => $row->left_reason]);
        $this->tell($this->owners($team, $me->id), 'freelance.team_member_left', 'freelance_team_member_left_title', 'freelance_team_member_left_body', ['name' => $this->first($me), 'team' => $team->name], $team);

        return $row;
    }

    /** An Owner removes somebody: FreelancerTeams::remove, plus the log and a word to the person. */
    public function remove(FreelanceTeam $team, Freelancer $by, Freelancer $target, ?string $reason): Member
    {
        $row = $this->teams->remove($team, $by, $target, $reason);
        app(TeamAssignments::class)->release($team, $target);
        $this->record('freelance.team.member_removed', $row, null, ['team' => $team->uuid, 'freelancer' => $target->id, 'by' => $by->id, 'reason' => $row->left_reason]);
        $this->tell(collect([$target]), 'freelance.team_member_removed', 'freelance_team_member_removed_title', 'freelance_team_member_removed_body', ['team' => $team->name], $team);

        return $row;
    }

    // -------------------------------------------------------------- shape

    /** @return array<string, mixed> */
    public function present(Invitation $i, bool $withToken = false): array
    {
        return [
            'uuid' => $i->uuid,
            'team' => $i->team === null ? null : ['uuid' => $i->team->uuid, 'name' => $i->team->name],
            'role' => $i->role,
            'by_link' => $i->token !== null,
            'invited' => $i->freelancer === null ? null : ['uuid' => $i->freelancer->uuid, 'name' => $i->freelancer->name],
            'invited_by' => $i->inviter === null ? null : ['name' => $i->inviter->name],
            'status' => $this->lapsed($i) ? Invitation::EXPIRED : $i->status,
            'expires_at' => $i->expires_at?->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
        ] + ($withToken && $i->token !== null && $i->status === Invitation::PENDING ? ['token' => $i->token] : []);
    }

    // -------------------------------------------------------------- inside

    private function answer(Freelancer $me, $query, bool $accept): Invitation
    {
        return DB::transaction(function () use ($me, $query, $accept): Invitation {
            $i = $query->lockForUpdate()->firstOrFail();
            $team = FreelanceTeam::query()->find($i->team_id) ?? throw new ModelNotFoundException;
            if ($i->status === Invitation::PENDING && $this->lapsed($i)) {
                $this->close($i, Invitation::EXPIRED);
            }
            $this->assertOpen($i);
            if ($i->token !== null && (int) $i->invited_by_freelancer_id === (int) $me->id) {
                throw new FreelanceException('freelancer_team_invite_self', 422);
            }

            if ($accept) {
                $this->teams->join($team, $me, $i->role);
                $i->forceFill(['status' => Invitation::ACCEPTED, 'responded_at' => now(), 'freelancer_id' => $i->freelancer_id ?? $me->id])->save();
            } else {
                $this->close($i, Invitation::DECLINED);
            }
            $this->record($accept ? 'freelance.team.invitation_accepted' : 'freelance.team.invitation_declined', $i, null, ['team' => $team->uuid, 'freelancer' => $me->id, 'by_link' => $i->token !== null]);
            $inviter = Freelancer::query()->find($i->invited_by_freelancer_id);
            if ($inviter !== null) {
                $this->tell(collect([$inviter]), $accept ? 'freelance.team_invitation_accepted' : 'freelance.team_invitation_declined',
                    $accept ? 'freelance_team_invitation_accepted_title' : 'freelance_team_invitation_declined_title',
                    $accept ? 'freelance_team_invitation_accepted_body' : 'freelance_team_invitation_declined_body', ['name' => $this->first($me), 'team' => $team->name], $team);
            }

            return $i->load(['team:id,uuid,name', 'freelancer:id,uuid,name']);
        });
    }

    private function byToken(string $token)
    {
        return Invitation::query()->whereNotNull('token')->where('token', $token);
    }

    private function assertOpen(Invitation $i): void
    {
        if ($i->status !== Invitation::PENDING || $this->lapsed($i)) {
            throw new FreelanceException('freelancer_team_invitation_closed', 409);
        }
    }

    private function lapsed(Invitation $i): bool
    {
        return $i->status === Invitation::PENDING && $i->expires_at !== null && $i->expires_at->isPast();
    }

    private function close(Invitation $i, string $status): void
    {
        $i->forceFill(['status' => $status, 'responded_at' => now()])->save();
    }

    /** A freelancer by phone (with or without the +), phone verified, not blocked or suspended - from any country. */
    private function find(string $phone): ?Freelancer
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 6) {
            return null;
        }

        return Freelancer::query()->whereIn('phone', ['+'.$digits, $digits])->whereNotNull('phone_verified_at')->whereNotIn('status', ['blocked', 'suspended'])->first();
    }

    /** @return Collection<int, Freelancer> */
    private function owners(FreelanceTeam $team, int $except): Collection
    {
        return Freelancer::query()->whereIn('id', Member::query()->where('team_id', $team->id)->where('status', Member::ACTIVE)->where('role', Member::OWNER)->where('freelancer_id', '!=', $except)->select('freelancer_id'))->get();
    }

    private function tell(Collection $to, string $event, string $title, string $body, array $vars, FreelanceTeam $team): void
    {
        if ($to->isNotEmpty()) {
            $this->notifications->send($to, $event, $title, $body, $vars, ['type' => str_replace('.', '_', $event), 'team' => $team->uuid]);
        }
    }

    private function record(string $action, \Illuminate\Database\Eloquent\Model $subject, ?array $before, array $after): void
    {
        $this->audit->record($action, $subject, $before, $after);
    }

    private function first(Freelancer $f): string
    {
        return strtok((string) $f->name, ' ') ?: (string) $f->name;
    }
}
