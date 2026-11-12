<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use App\Services\General\AuditLogger;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;

/**
 * FS-208: what staff do to a freelancers' team - suspend it with a reason, bring it back, take somebody out. Every step is written to the audit
 * log and the people it concerns are told (the Owners of the team; the person taken out). A suspended team keeps working for nobody but what
 * is already running is not touched: its people still read, and leaving stays possible at once (D5).
 */
class TeamModeration
{
    public const REASON_MAX = 255;

    public function __construct(
        private readonly FreelancerTeams $teams,
        private readonly TeamAssignments $assignments,
        private readonly NotificationCenter $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Suspends an active team (a reason is needed) or brings a suspended one back. 409 team_status_unchanged, 422 team_suspend_reason_required,
     * 409 team_archived (an archived team is not changed here).
     */
    public function setStatus(FreelanceTeam $team, string $status, ?string $reason): FreelanceTeam
    {
        $reason = $reason === null ? null : mb_substr(trim(strip_tags($reason)), 0, self::REASON_MAX);
        $before = ['status' => $team->status, 'status_reason' => $team->status_reason];

        $team = DB::transaction(function () use ($team, $status, $reason): FreelanceTeam {
            $locked = FreelanceTeam::query()->lockForUpdate()->findOrFail($team->id);
            if ($locked->status === FreelanceTeam::ARCHIVED) {
                throw new FreelanceException('team_archived', 409);
            }
            if ($locked->status === $status) {
                throw new FreelanceException('team_status_unchanged', 409);
            }
            if ($status === FreelanceTeam::SUSPENDED && ($reason === null || $reason === '')) {
                throw new FreelanceException('team_suspend_reason_required', 422);
            }
            $locked->forceFill(['status' => $status, 'status_reason' => $status === FreelanceTeam::SUSPENDED ? $reason : null, 'status_changed_at' => now()])->save();

            return $locked;
        });

        $this->audit->record('freelance.team.status_changed', $team, $before, ['status' => $team->status, 'reason' => $reason]);
        $suspended = $status === FreelanceTeam::SUSPENDED;
        $this->notifications->send($this->owners($team), $suspended ? 'freelance.team_suspended' : 'freelance.team_reactivated',
            $suspended ? 'freelance_team_suspended_title' : 'freelance_team_reactivated_title', $suspended ? 'freelance_team_suspended_body' : 'freelance_team_reactivated_body',
            ['team' => $team->name, 'reason' => (string) $reason], ['type' => $suspended ? 'freelance_team_suspended' : 'freelance_team_reactivated', 'team' => $team->uuid]);

        return $team;
    }

    /** Takes a member out (D6: not the last Owner). What he held on the team's projects lets go at once (D5), and he is told. */
    public function removeMember(FreelanceTeam $team, Freelancer $target, ?string $reason): Member
    {
        $row = $this->teams->removeByStaff($team, $target, $reason);
        $this->assignments->release($team, $target);
        $this->audit->record('freelance.team.member_removed_by_staff', $team, null, ['freelancer' => $target->id, 'reason' => $row->left_reason]);
        $this->notifications->send(collect([$target]), 'freelance.team_member_removed_by_staff', 'freelance_team_member_removed_staff_title', 'freelance_team_member_removed_staff_body',
            ['team' => $team->name], ['type' => 'freelance_team_member_removed_by_staff', 'team' => $team->uuid]);

        return $row;
    }

    /** @return \Illuminate\Support\Collection<int, Freelancer> */
    private function owners(FreelanceTeam $team)
    {
        return Freelancer::query()->whereIn('id', Member::query()->where('team_id', $team->id)->where('role', Member::OWNER)->where('status', Member::ACTIVE)->select('freelancer_id'))->get();
    }
}
