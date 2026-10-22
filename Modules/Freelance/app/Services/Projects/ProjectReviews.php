<?php

namespace Modules\Freelance\Services\Projects;

use App\Services\General\AuditLogger;
use Modules\Freelance\Enums\FreelanceProjectStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * The reviewer's side of a project (FL-203): fix what is wrong in a project waiting for review, then decide — publish it, ask the
 * client for changes, or reject it (a reason is always given). Rejected is not the end: the client reads the reason, edits the
 * project and sends it again. Every move goes through ProjectStateMachine (an event with the admin as actor), is audited, and the
 * client is told at once.
 */
class ProjectReviews
{
    public function __construct(
        private readonly ProjectStateMachine $machine,
        private readonly ClientProjects $projects,
        private readonly FreelanceNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly ProjectOffersWindow $window,
    ) {}

    /** The reviewer fixes a typo or a number in a project that is still in the queue; the change is on record. */
    public function edit(Project $project, array $data, int $adminId): Project
    {
        $before = $this->snapshot($project);
        $project = $this->projects->update($project, $data, asReviewer: true);
        $after = $this->snapshot($project);

        $changed = array_keys(array_filter($after, fn ($v, $k) => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $this->machine->record($project, 'project.edited_by_admin', 'admin', $adminId, ['fields' => $changed]);
            $this->audit->record('freelance_project.edited', $project, $before, $after);
        }

        return $project;
    }

    public function publish(Project $project, int $adminId): Project
    {
        $this->projects->assertReadyToPublish($project);
        $moved = $this->machine->move($project, Status::Published, 'project.published', 'admin', $adminId, ['published_at' => now(), 'status_reason' => null, 'offers_deadline_at' => $this->window->deadlineFor($project), 'offers_closed_at' => null, 'offers_close_reason' => null]);
        $this->audit->record('freelance_project.published', $moved, null, ['status' => 'published']);
        $this->notifier->notify($moved->client, 'project.published', ['project' => (string) $moved->title], ['project' => $moved->uuid]);

        return $moved;
    }

    public function requestChanges(Project $project, string $reason, int $adminId): Project
    {
        return $this->decide($project, Status::ChangesRequested, 'project.changes_requested', 'project.changes_requested', $reason, $adminId);
    }

    public function reject(Project $project, string $reason, int $adminId): Project
    {
        return $this->decide($project, Status::Rejected, 'project.rejected', 'project.rejected', $reason, $adminId);
    }

    private function decide(Project $project, Status $to, string $event, string $notice, string $reason, int $adminId): Project
    {
        $reason = trim(strip_tags($reason));
        if ($reason === '') {
            throw new FreelanceException('project_reason_required', 422);
        }
        $moved = $this->machine->move($project, $to, $event, 'admin', $adminId, ['status_reason' => $reason], ['reason' => $reason]);
        $this->audit->record('freelance_project.'.$to->value, $moved, null, ['status' => $to->value, 'reason' => $reason]);
        $this->notifier->notify($moved->client, $notice, ['project' => (string) $moved->title, 'reason' => $reason], ['project' => $moved->uuid]);

        return $moved;
    }

    /** @return array<string, mixed> the parts a reviewer may change, for the before / after of the audit */
    private function snapshot(Project $project): array
    {
        return [
            'title' => $project->title,
            'description' => $project->description,
            'specialty_id' => $project->freelance_specialty_id,
            'skills' => $project->skills()->pluck('freelance_skills.id')->sort()->values()->all(),
            'budget_type' => $project->budget_type,
            'budget_min_minor' => $project->budget_min_minor,
            'budget_max_minor' => $project->budget_max_minor,
            'duration_days' => $project->duration_days,
            'visibility' => $project->visibility,
        ];
    }
}
