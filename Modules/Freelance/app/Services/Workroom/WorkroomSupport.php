<?php

namespace Modules\Freelance\Services\Workroom;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Freelance\Events\FreelanceTeamWorkroomChanged;
use Modules\Freelance\Events\FreelanceWorkroomChanged;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceProjectEvent;
use Throwable;

/**
 * The few steps every Workroom write shares (tasks, files, notes): lock the agreement and refuse a Workroom that is only readable now,
 * write the step on the project's record, check a part belongs to the agreement, and tell the live screens once after the commit.
 */
final class WorkroomSupport
{
    /** Locks the agreement (so limits and changes are counted one at a time) and refuses a Workroom that is no longer writable. */
    public static function lockActive(WorkroomContext $c): void
    {
        $locked = Agreement::query()->lockForUpdate()->findOrFail($c->agreement->id);
        if ($locked->status !== Agreement::ACTIVE) {
            throw new FreelanceException('workroom_not_active', 409);
        }
    }

    /** One of the agreement's parts by its uuid; null when none is given, 422 when it is not this agreement's. */
    public static function milestone(WorkroomContext $c, ?string $uuid): ?FreelanceMilestone
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $milestone = FreelanceMilestone::query()->where('agreement_id', $c->agreement->id)->where('uuid', $uuid)->first();
        if ($milestone === null) {
            throw new FreelanceException('workroom_milestone_invalid', 422);
        }

        return $milestone;
    }

    /**
     * FS-207: the scope a new task or file gets - `shared` (both sides) or `team` (the freelancers team only: a team's project, the freelancer
     * side). 422 workroom_scope_invalid otherwise.
     */
    public static function scope(WorkroomContext $c, ?string $scope): string
    {
        if ($scope === null || $scope === '' || $scope === 'shared') {
            return 'shared';
        }
        if ($scope !== 'team' || $c->side !== 'freelancer' || $c->agreement->team_id === null) {
            throw new FreelanceException('workroom_scope_invalid', 422);
        }

        return 'team';
    }

    /** FS-207: what the client may not read is left out of his queries (team items). */
    public static function visible(\Illuminate\Database\Eloquent\Builder $query, WorkroomContext $c): \Illuminate\Database\Eloquent\Builder
    {
        return $c->side === 'client' ? $query->where($query->getModel()->getTable().'.scope', 'shared') : $query;
    }

    /** @param  array<string, mixed>  $payload */
    public static function record(WorkroomContext $c, string $type, array $payload, string $scope = 'shared'): void
    {
        FreelanceProjectEvent::query()->create(($scope === 'team' ? ['audience' => 'team'] : []) + [
            'project_id' => $c->project->id,
            'type' => $type,
            'actor_type' => $c->side,
            'actor_id' => (int) $c->actor->getKey(),
            'payload' => $payload,
        ]);
    }

    /** After the step is saved: the live screens of the project reload once (no text travels, only what changed). */
    public static function changed(WorkroomContext $c, string $kind, string $action, string $uuid, string $scope = 'shared'): void
    {
        if ($scope === 'team') {
            // The client's screens listen on the project's channel: nothing travels there for a team item. FS-209: it goes to the team's own
            // channel (its active people only), with the project, so a teammate's open Workroom reloads once.
            $team = $c->agreement->team_id === null ? null : $c->agreement->team;
            if ($team !== null) {
                DB::afterCommit(function () use ($c, $team, $kind, $action, $uuid): void {
                    try {
                        broadcast(new FreelanceTeamWorkroomChanged($team->uuid, $c->project->uuid, $kind, $action, $uuid, $c->side));
                    } catch (Throwable $e) {
                        Log::warning('[Freelance] team workroom broadcast failed: '.$e->getMessage());
                    }
                });
            }

            return;
        }
        DB::afterCommit(function () use ($c, $kind, $action, $uuid): void {
            try {
                broadcast(new FreelanceWorkroomChanged($c->project->uuid, $kind, $action, $uuid, $c->side));
            } catch (Throwable $e) {
                Log::warning('[Freelance] workroom broadcast failed: '.$e->getMessage());
            }
        });
    }
}
