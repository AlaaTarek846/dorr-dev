<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\FreelanceMilestoneAssignee as Assignee;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceProjectEvent;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\FreelanceWorkroomTask as Task;
use Modules\Freelance\Models\Freelancer;

/**
 * FS-206b: who of the team works on which part and which task of a team's project. An Owner or a PM puts people of the team on a part
 * (any active member - Owner, PM or member); a task is given to a person the same way, and a plain member may only take a task for himself.
 * A person put on a part or a task reaches the project (D9: TeamProjects::people) and a member starts the part he is on. Leaving the team
 * (or being removed) lets all of it go at once and tells the Owners what is left without a person (D5). What is written here is the team's
 * own: the client never sees it.
 */
class TeamAssignments
{
    public function __construct(
        private readonly NotificationCenter $notifications,
        private readonly FreelancerTeamContext $context,
    ) {}

    // -------------------------------------------------------------- reading

    /** @return Collection<int, Freelancer> the people on a part */
    public function onMilestone(Milestone $m): Collection
    {
        return Freelancer::query()->whereIn('id', Assignee::query()->where('milestone_id', $m->id)->pluck('freelancer_id'))->get(['id', 'uuid', 'name']);
    }

    /**
     * FS-209b: who is on each part of an agreement, in one query - for the freelancer side of the agreement page.
     *
     * @return array<int, list<array{uuid: string, name: string}>> part id => the people on it
     */
    public function byMilestone(Agreement $a): array
    {
        $out = [];
        Assignee::query()->whereIn('milestone_id', Milestone::query()->where('agreement_id', $a->id)->select('id'))->with('freelancer:id,uuid,name')->orderBy('id')->get()
            ->each(function (Assignee $row) use (&$out) {
                if ($row->freelancer !== null) {
                    $out[(int) $row->milestone_id][] = ['uuid' => (string) $row->freelancer->uuid, 'name' => (string) $row->freelancer->name];
                }
            });

        return $out;
    }

    /** Is the person on this part? */
    public function isOnMilestone(Agreement $a, Freelancer $who, string $milestoneUuid): bool
    {
        return Assignee::query()->where('freelancer_id', $who->id)
            ->whereIn('milestone_id', Milestone::query()->where('agreement_id', $a->id)->where('uuid', $milestoneUuid)->select('id'))->exists();
    }

    /**
     * FS-207: what each active member of the team carries now, on the team's running projects (agreements that are active): the parts he is
     * put on that are not over, and his tasks (open, overdue, blocked, done). Members with nothing are listed too. `$only` limits it to one
     * person (a plain member reads his own row).
     *
     * @return list<array<string, mixed>>
     */
    public function workload(FreelanceTeam $team, ?int $only = null): array
    {
        $agreements = Agreement::query()->where('team_id', $team->id)->where('status', Agreement::ACTIVE)->pluck('id');
        $over = ['approved', 'cancelled'];
        $parts = Assignee::query()->join('freelance_milestones as m', 'm.id', '=', 'freelance_milestone_assignees.milestone_id')
            ->whereIn('m.agreement_id', $agreements)->whereNotIn('m.status', $over)
            ->get(['freelance_milestone_assignees.freelancer_id', 'm.agreement_id'])->groupBy('freelancer_id');
        $tasks = Task::query()->whereIn('agreement_id', $agreements)->whereNotNull('assignee_freelancer_id')
            ->get(['assignee_freelancer_id', 'agreement_id', 'status', 'due_on'])->groupBy('assignee_freelancer_id');
        $today = now()->startOfDay();

        return Member::query()->where('team_id', $team->id)->where('status', Member::ACTIVE)->when($only !== null, fn ($q) => $q->where('freelancer_id', $only))
            ->with('freelancer:id,uuid,name')->orderBy('id')->get()->map(function (Member $m) use ($parts, $tasks, $today): array {
                $p = $parts->get($m->freelancer_id, collect());
                $t = $tasks->get($m->freelancer_id, collect());
                $open = $t->where('status', '!=', Task::DONE);

                return [
                    'freelancer' => ['uuid' => $m->freelancer?->uuid, 'name' => $m->freelancer?->name],
                    'role' => $m->role,
                    'projects' => $p->pluck('agreement_id')->merge($t->pluck('agreement_id'))->unique()->count(),
                    'parts_open' => $p->count(),
                    'tasks_open' => $open->count(),
                    'tasks_overdue' => $open->filter(fn ($x) => $x->due_on !== null && $x->due_on->lt($today))->count(),
                    'tasks_blocked' => $t->where('status', Task::BLOCKED)->count(),
                    'tasks_done' => $t->where('status', Task::DONE)->count(),
                ];
            })->values()->all();
    }

    /** @return list<string> the uuids of the parts the person is put on */
    public function ownParts(Agreement $a, Freelancer $who): array
    {
        return Milestone::query()->where('agreement_id', $a->id)
            ->whereIn('id', Assignee::query()->where('freelancer_id', $who->id)->select('milestone_id'))->pluck('uuid')->all();
    }

    /** @return list<int> everybody put on a part or a task of the agreement (they reach the project, D9) */
    public function involved(Agreement $a): array
    {
        $parts = Assignee::query()->whereIn('milestone_id', Milestone::query()->where('agreement_id', $a->id)->select('id'))->pluck('freelancer_id');
        $tasks = Task::query()->where('agreement_id', $a->id)->whereNotNull('assignee_freelancer_id')->pluck('assignee_freelancer_id');

        return $parts->merge($tasks)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    // -------------------------------------------------------------- writing

    /**
     * Put exactly these people on a part (an empty list takes everybody off). 422 team_assignee_invalid (somebody not an active member of the
     * team), 422 team_assign_closed (the agreement is over), 422 team_project_required (not a team's project).
     *
     * @param  list<string>  $uuids  freelancer uuids
     * @return Collection<int, Freelancer>
     */
    public function setMilestone(Agreement $a, Milestone $m, array $uuids, Freelancer $by): Collection
    {
        $team = $this->team($a);
        $people = $this->members($team, $uuids);
        $acting = $this->context->actor() ?? $by;

        $added = DB::transaction(function () use ($a, $m, $people, $acting): array {
            $fresh = Agreement::query()->lockForUpdate()->findOrFail($a->id);
            if ($fresh->status !== Agreement::ACTIVE) {
                throw new FreelanceException('team_assign_closed', 422);
            }
            $have = Assignee::query()->where('milestone_id', $m->id)->pluck('freelancer_id')->map(fn ($id) => (int) $id)->all();
            $want = $people->pluck('id')->map(fn ($id) => (int) $id)->all();
            $gone = array_values(array_diff($have, $want));
            $new = array_values(array_diff($want, $have));
            if ($gone !== []) {
                Assignee::query()->where('milestone_id', $m->id)->whereIn('freelancer_id', $gone)->delete();
            }
            foreach ($new as $id) {
                Assignee::query()->create(['milestone_id' => $m->id, 'freelancer_id' => $id, 'assigned_by_freelancer_id' => $acting->id]);
            }
            if ($gone !== [] || $new !== []) {
                $this->record($fresh, 'team.milestone.assignees', $acting, ['milestone' => $m->uuid, 'title' => $m->title, 'added' => $new, 'removed' => $gone]);
            }

            return $new;
        });

        $this->told($people->whereIn('id', $added), $team, $a, (string) $m->title, $acting);

        return $this->onMilestone($m);
    }

    /**
     * Who a task is given to (null = nobody). The person must be an active member of the team; a plain member only takes it himself.
     * 422 team_assignee_invalid, 422 team_project_required, 403 team_assign_forbidden.
     */
    public function taskAssignee(Agreement $a, ?string $uuid, Freelancer $by): ?Freelancer
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $team = $this->team($a);
        $who = $this->members($team, [$uuid])->first();
        $acting = $this->context->actor() ?? $by;
        if ($this->context->role() === TeamProjects::MEMBER && (int) $who->id !== (int) $acting->id) {
            throw new FreelanceException('team_assign_forbidden', 403);
        }

        return $who;
    }

    /** After a task is saved with a new person: tell him (unless he did it himself). */
    public function taskGiven(Agreement $a, Task $task, Freelancer $who, Freelancer $by): void
    {
        $acting = $this->context->actor() ?? $by;
        $team = FreelanceTeam::query()->find($a->team_id);
        if ($team !== null) {
            $this->told(collect([$who]), $team, $a, (string) $task->title, $acting);
        }
    }

    /**
     * D5: a person left the team (or was removed): every part and task he held lets go at once, and the Owners of each project hear what is
     * left without a person.
     */
    public function release(FreelanceTeam $team, Freelancer $who): void
    {
        $agreements = Agreement::query()->where('team_id', $team->id)->whereIn('status', [Agreement::ACTIVE, Agreement::PENDING])->get();
        foreach ($agreements as $a) {
            $parts = Assignee::query()->where('freelancer_id', $who->id)->whereIn('milestone_id', Milestone::query()->where('agreement_id', $a->id)->select('id'))->get();
            $tasks = Task::query()->where('agreement_id', $a->id)->where('assignee_freelancer_id', $who->id)->get();
            if ($parts->isEmpty() && $tasks->isEmpty()) {
                continue;
            }
            DB::transaction(function () use ($parts, $tasks, $a, $who): void {
                Assignee::query()->whereIn('id', $parts->pluck('id'))->delete();
                Task::query()->whereIn('id', $tasks->pluck('id'))->update(['assignee_freelancer_id' => null]);
                $this->record($a, 'team.assignments.released', null, ['freelancer' => $who->id, 'milestones' => $parts->count(), 'tasks' => $tasks->count()]);
            });
            $project = Project::query()->find($a->project_id);
            $owners = Freelancer::query()->whereIn('id', Member::query()->where('team_id', $team->id)->where('role', Member::OWNER)->where('status', Member::ACTIVE)->pluck('freelancer_id'))->get();
            $this->notifications->send($owners, 'freelance.team_assignments_released', 'freelance_team_assignments_released_title', 'freelance_team_assignments_released_body',
                ['name' => strtok((string) $who->name, ' ') ?: (string) $who->name, 'team' => $team->name, 'project' => (string) $project?->title, 'count' => $parts->count() + $tasks->count()],
                ['type' => 'freelance_team_assignments_released', 'project' => $project?->uuid, 'team' => $team->uuid]);
        }
    }

    // -------------------------------------------------------------- inside

    private function team(Agreement $a): FreelanceTeam
    {
        $team = $a->team_id === null ? null : FreelanceTeam::query()->find($a->team_id);
        if ($team === null) {
            throw new FreelanceException('team_project_required', 422);
        }

        return $team;
    }

    /**
     * @param  list<string>  $uuids
     * @return Collection<int, Freelancer>
     */
    private function members(FreelanceTeam $team, array $uuids): Collection
    {
        $uuids = array_values(array_unique($uuids));
        if ($uuids === []) {
            return collect();
        }
        $people = Freelancer::query()->whereIn('uuid', $uuids)->whereIn('id', Member::query()->where('team_id', $team->id)->where('status', Member::ACTIVE)->select('freelancer_id'))->get();
        if ($people->count() !== count($uuids)) {
            throw new FreelanceException('team_assignee_invalid', 422);
        }

        return $people;
    }

    /** @param  Collection<int, Freelancer>  $people */
    private function told(Collection $people, FreelanceTeam $team, Agreement $a, string $what, Freelancer $by): void
    {
        $people = $people->filter(fn (Freelancer $p) => (int) $p->id !== (int) $by->id)->values();
        if ($people->isEmpty()) {
            return;
        }
        $project = Project::query()->find($a->project_id);
        $this->notifications->send($people, 'freelance.team_assigned', 'freelance_team_assigned_title', 'freelance_team_assigned_body',
            ['team' => $team->name, 'what' => $what, 'project' => (string) $project?->title], ['type' => 'freelance_team_assigned', 'project' => $project?->uuid, 'team' => $team->uuid]);
    }

    /** @param  array<string, mixed>  $payload */
    private function record(Agreement $a, string $type, ?Freelancer $by, array $payload): void
    {
        FreelanceProjectEvent::query()->create([
            'project_id' => $a->project_id,
            'type' => $type,
            'actor_type' => 'freelancer',
            'actor_id' => (int) ($by?->id ?? $a->freelancer_id),
            'payload' => $payload,
            'audience' => 'team',
        ]);
    }
}
