<?php

namespace Modules\Freelance\Services\Projects;

use Illuminate\Database\Eloquent\Collection;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceProjectAlert;
use Modules\Freelance\Models\FreelanceProjectInvitation;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * Projects that fit a freelancer (FL-208): when a public project opens, the freelancers it suits are told once — in the app, live and
 * by push. The match is by plain rules, no scoring model:
 *
 *  - a verified freelancer of the project's country (the same ones who can see and offer on it);
 *  - the project's specialty is one of theirs, or sits under one of theirs (what the project list shows them by default);
 *  - the skills: when both sides have skills, at least one is shared (more shared skills = told first);
 *  - open to offers today (not paused, not on time off);
 *  - the budget: a freelancer with a fixed guide price is told only when the project's budget meets their range.
 *
 * Nobody is told twice about one project, nor about a project they were invited to or already offered on. At most
 * `freelance.matching.max_recipients` are told per project (the best fits first).
 */
class ProjectMatching
{
    public function __construct(private readonly FreelanceNotifier $notifier) {}

    /** @return Collection<int, Freelancer> the freelancers this project suits, best fit first (each with its `overlap` count) */
    public function matches(Project $project): Collection
    {
        $today = now()->toDateString();
        $skills = $project->skills()->pluck('freelance_skills.id')->map(fn ($i) => (int) $i)->all();
        $specialties = array_values(array_filter([(int) $project->freelance_specialty_id, (int) $project->specialty?->parent_id]));
        $low = (int) $project->budget_min_minor;
        $high = $project->budget_type === 'range' ? (int) ($project->budget_max_minor ?? $low) : $low;

        return Freelancer::query()
            ->where('status', FreelancerStatus::Verified->value)
            ->where('country_id', $project->country_id)
            ->whereHas('specialties', fn ($q) => $q->whereIn('freelance_specialties.id', $specialties))
            ->withCount(['skills as overlap' => fn ($q) => $q->whereIn('freelance_skills.id', $skills === [] ? [0] : $skills)])
            ->when($skills !== [], fn ($q) => $q->where(fn ($w) => $w->whereDoesntHave('skills')->orWhereHas('skills', fn ($s) => $s->whereIn('freelance_skills.id', $skills))))
            // Open to offers today.
            ->where(fn ($q) => $q->whereDoesntHave('availability')->orWhereHas('availability', fn ($a) => $a->where('accepting_requests', true)
                ->orWhere(fn ($b) => $b->whereNotNull('paused_until')->whereDate('paused_until', '<', $today))))
            ->whereDoesntHave('timeOff', fn ($t) => $t->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today))
            // A fixed guide price that the budget does not meet.
            ->whereDoesntHave('availability', fn ($a) => $a->where('rate_type', 'fixed')
                ->where(fn ($r) => $r->where(fn ($m) => $m->whereNotNull('rate_max_minor')->where('rate_max_minor', '<', $low))
                    ->orWhere(fn ($m) => $m->whereNotNull('rate_min_minor')->where('rate_min_minor', '>', $high))))
            ->whereNotIn('id', FreelanceProjectAlert::query()->where('project_id', $project->id)->select('freelancer_id'))
            ->whereNotIn('id', FreelanceProjectInvitation::query()->where('project_id', $project->id)->select('freelancer_id'))
            ->whereDoesntHave('offers', fn ($o) => $o->where('project_id', $project->id))
            ->orderByDesc('overlap')
            ->orderByDesc('last_active_at')
            ->orderBy('id')
            ->limit((int) config('freelance.matching.max_recipients', 200))
            ->get();
    }

    /** Tells every freelancer the project suits (once each). @return int how many were told */
    public function alert(Project $project): int
    {
        $project->loadMissing('specialty');
        if ($project->status->value !== 'published' || $project->visibility !== 'public') {
            return 0;
        }

        $told = 0;
        foreach ($this->matches($project) as $freelancer) {
            $alert = FreelanceProjectAlert::query()->firstOrCreate(
                ['project_id' => $project->id, 'freelancer_id' => $freelancer->id],
                ['score' => (int) $freelancer->overlap],
            );
            if (! $alert->wasRecentlyCreated) {
                continue;
            }
            $this->notifier->notify($freelancer, 'project.matched', ['project' => (string) $project->title], ['project' => $project->uuid]);
            $told++;
        }

        return $told;
    }
}
