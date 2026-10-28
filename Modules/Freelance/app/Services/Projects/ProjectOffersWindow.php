<?php

namespace Modules\Freelance\Services\Projects;

use Modules\Freelance\Enums\FreelanceProjectStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Policy\FreelancePolicies;

/**
 * The window in which a published project takes offers (FL-204). Its length is the policy `offers.window_days` of the client's
 * country (and specialty), read when the window starts. Closing the window leaves the project `published` — only the offers
 * stop; it closes by itself when the time is up (the scheduled command, and in any case at the moment someone tries to offer, since
 * `accepting()` looks at the clock), or by hand; it can be opened again, a few times for the client and freely for an admin.
 */
class ProjectOffersWindow
{
    public function __construct(
        private readonly FreelancePolicies $policies,
        private readonly ProjectStateMachine $machine,
        private readonly FreelanceNotifier $notifier,
    ) {}

    /** When a window that starts now ends, by the policy of the project's country and specialty. */
    public function deadlineFor(Project $project): \Illuminate\Support\Carbon
    {
        $days = (int) $this->policies->for($project->country_id, $project->freelance_specialty_id)->get('offers.window_days');

        return now()->addDays(max(1, $days));
    }

    /** The one rule offers (FL-301) ask: published, not closed, and the time not up. */
    public function accepting(Project $project): bool
    {
        return $project->status === Status::Published
            && $project->offers_closed_at === null
            && ($project->offers_deadline_at === null || $project->offers_deadline_at->isFuture());
    }

    /** @throws FreelanceException freelance_project_offers_closed (422) */
    public function assertAccepting(Project $project): void
    {
        if (! $this->accepting($project)) {
            throw new FreelanceException('project_offers_closed', 422);
        }
    }

    /** Closes the offers by hand (the client, or an admin). */
    public function close(Project $project, string $actorType, ?int $actorId): Project
    {
        $this->assertPublished($project);
        if (! $this->accepting($project)) {
            throw new FreelanceException('project_offers_not_open', 409);
        }

        return $this->markClosed($project, $actorType === 'admin' ? 'admin' : 'client', $actorType, $actorId);
    }

    /** Opens the offers again for a new window (not before it has closed or run out). */
    public function reopen(Project $project, string $actorType, ?int $actorId): Project
    {
        $this->assertPublished($project);
        if ($this->accepting($project)) {
            throw new FreelanceException('project_offers_already_open', 409);
        }
        $max = (int) config('freelance.projects.max_reopens', 3);
        if ($actorType === 'client' && $project->reopen_count >= $max) {
            throw new FreelanceException('project_reopen_limit', 422, ['max' => $max], ['max' => $max]);
        }

        $project->update(['offers_deadline_at' => $this->deadlineFor($project), 'offers_closed_at' => null, 'offers_close_reason' => null, 'reopen_count' => $project->reopen_count + 1]);
        $this->machine->record($project, 'project.offers_reopened', $actorType, $actorId, ['deadline' => $project->offers_deadline_at->toIso8601String()]);

        return $project->refresh();
    }

    /** The offer that reaches the project's limit closes it to more (FL-301). */
    public function closeBecauseFull(Project $project): Project
    {
        return $this->markClosed($project, 'max_offers', 'system', null);
    }

    /** The client chose an offer (FL-309): no more offers, whatever the window said. Inside the caller's transaction. */
    public function closeBecauseChosen(Project $project, int $clientId): Project
    {
        return $this->markClosed($project, 'chosen', 'client', $clientId);
    }

    /** Closes every published project whose time is up and tells its client. Safe to run any number of times. */
    public function closeExpired(): int
    {
        $closed = 0;
        Project::query()->where('status', Status::Published->value)->whereNull('offers_closed_at')->whereNotNull('offers_deadline_at')->where('offers_deadline_at', '<=', now())
            ->with('client')->each(function (Project $project) use (&$closed): void {
                $this->markClosed($project, 'deadline', 'system', null);
                if ($project->client !== null) {
                    $this->notifier->notify($project->client, 'project.offers_closed', ['project' => (string) $project->title], ['project' => $project->uuid]);
                }
                $closed++;
            });

        return $closed;
    }

    /** @return array<string, mixed> what the apps show of the window */
    public function present(Project $project, bool $client): array
    {
        $published = $project->status === Status::Published;
        $awarded = $project->status === Status::Awarded;
        $accepting = $this->accepting($project);
        $max = (int) config('freelance.projects.max_reopens', 3);

        return [
            'accepting' => $accepting,
            'deadline_at' => $published ? $project->offers_deadline_at?->toIso8601String() : null,
            'closed_at' => $project->offers_closed_at?->toIso8601String(),
            // `deadline` also when the time simply ran out before the command got to it.
            'close_reason' => $awarded ? 'chosen' : ($published && ! $accepting ? ($project->offers_close_reason ?? 'deadline') : null),
        ] + ($client ? [
            'can_close' => $published && $accepting,
            'can_reopen' => $published && ! $accepting && $project->reopen_count < $max,
            'reopens_left' => max(0, $max - $project->reopen_count),
        ] : []);
    }

    private function markClosed(Project $project, string $reason, string $actorType, ?int $actorId): Project
    {
        $project->update(['offers_closed_at' => now(), 'offers_close_reason' => $reason]);
        $this->machine->record($project, 'project.offers_closed', $actorType, $actorId, ['reason' => $reason]);

        return $project->refresh();
    }

    private function assertPublished(Project $project): void
    {
        if ($project->status !== Status::Published) {
            throw new FreelanceException('project_invalid_state', 409, ['status' => __('freelance.project_statuses.'.$project->status->value)], ['status' => $project->status->value]);
        }
    }
}
