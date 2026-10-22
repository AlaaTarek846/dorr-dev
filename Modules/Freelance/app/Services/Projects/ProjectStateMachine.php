<?php

namespace Modules\Freelance\Services\Projects;

use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceProjectEvent;

/**
 * The only door to a project's status (FL-202), like RideStateMachine: checks the move is allowed, bumps `version` and writes the
 * `freelance_project_events` row in the same transaction. A move that isn't allowed from where the project is now answers
 * `freelance_project_invalid_state` (409) with the current status.
 */
class ProjectStateMachine
{
    /**
     * @param  array<string, mixed>  $attributes  other columns to set with the move
     * @param  array<string, mixed>  $payload
     */
    public function move(
        FreelanceProject $project,
        FreelanceProjectStatus $to,
        string $event,
        string $actorType,
        ?int $actorId,
        array $attributes = [],
        array $payload = [],
    ): FreelanceProject {
        return DB::transaction(function () use ($project, $to, $event, $actorType, $actorId, $attributes, $payload) {
            /** @var FreelanceProject $locked */
            $locked = FreelanceProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canMoveTo($to)) {
                throw new FreelanceException('project_invalid_state', 409, ['status' => __('freelance.project_statuses.'.$from->value)], ['status' => $from->value]);
            }

            $locked->fill($attributes);
            $locked->status = $to;
            $locked->version = $locked->version + 1;
            $locked->save();

            $this->record($locked, $event, $actorType, $actorId, $payload, $from, $to);

            return $locked;
        });
    }

    /** @param  array<string, mixed>  $payload */
    public function record(
        FreelanceProject $project,
        string $event,
        string $actorType,
        ?int $actorId,
        array $payload = [],
        ?FreelanceProjectStatus $from = null,
        ?FreelanceProjectStatus $to = null,
    ): FreelanceProjectEvent {
        return FreelanceProjectEvent::query()->create([
            'project_id' => $project->id,
            'type' => $event,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }
}
