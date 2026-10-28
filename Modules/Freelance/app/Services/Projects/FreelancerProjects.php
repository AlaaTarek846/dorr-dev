<?php

namespace Modules\Freelance\Services\Projects;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceProjectStatus as Status;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;

/**
 * Projects as the freelancer sees them (FL-206 / FL-207): the published, public projects of their own country — nothing else,
 * not even by guessing a uuid. A private project, a project of another country and a project that is not published are
 * simply not found. The client's name and phone are never shown, and the files only after an offer is accepted (later).
 */
class FreelancerProjects
{
    /** @return Builder<Project> what this freelancer may see */
    private function visible(Freelancer $freelancer): Builder
    {
        return Project::query()
            ->where('status', Status::Published->value)
            ->where('visibility', 'public')
            ->where('country_id', $freelancer->country_id);
    }

    /**
     * Which part of the projects this freelancer is shown: their own specialties (and the sub-specialties under them), or all.
     * Without a choice it is their own when they have any, and all when they have none yet. A specialty picked in the filter wins.
     *
     * @return array{scope: string, has_specialties: bool, ids: list<int>}
     */
    public function scopeFor(Freelancer $freelancer, ?string $requested, ?int $pickedSpecialty): array
    {
        $own = $freelancer->specialties()->pluck('freelance_specialties.id')->map(fn ($i) => (int) $i)->all();
        $has = $own !== [];
        $scope = $pickedSpecialty !== null ? 'all' : ($requested ?? ($has ? 'mine' : 'all'));
        if (! $has) {
            $scope = 'all';
        }
        $ids = $scope === 'mine' ? FreelanceSpecialty::query()->whereIn('parent_id', $own)->pluck('id')->map(fn ($i) => (int) $i)->merge($own)->unique()->values()->all() : [];

        return ['scope' => $scope, 'has_specialties' => $has, 'ids' => $ids];
    }

    /**
     * @param  array{q?: ?string, specialty_id?: ?int, budget_min_minor?: ?int, budget_max_minor?: ?int, duration_max_days?: ?int, favorites?: ?bool}  $f
     * @param  list<int>  $mine  the specialty ids to keep to (empty = no restriction)
     * @return LengthAwarePaginator<Project>
     */
    public function browse(Freelancer $freelancer, array $f, int $perPage, array $mine = []): LengthAwarePaginator
    {
        return $this->visible($freelancer)
            ->with(['specialty.translations', 'skills.translations'])
            ->withCount('media')
            ->when(! empty($f['favorites']), fn ($q) => $q->whereIn('id', $freelancer->projectFavorites()->select('project_id')))
            ->when(! empty($f['q']), function ($q) use ($f) {
                $term = '%'.addcslashes(trim($f['q']), '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->when($mine !== [] && empty($f['specialty_id']) && empty($f['favorites']), fn ($q) => $q->whereIn('freelance_specialty_id', $mine))
            ->when(! empty($f['specialty_id']), fn ($q) => $q->whereIn('freelance_specialty_id', $this->withChildren((int) $f['specialty_id'])))
            // A project fits when its budget overlaps what the freelancer asks for (a fixed budget is a range of one number).
            ->when(isset($f['budget_min_minor']), fn ($q) => $q->whereRaw('coalesce(budget_max_minor, budget_min_minor) >= ?', [(int) $f['budget_min_minor']]))
            ->when(isset($f['budget_max_minor']), fn ($q) => $q->where('budget_min_minor', '<=', (int) $f['budget_max_minor']))
            ->when(isset($f['duration_max_days']), fn ($q) => $q->where('duration_days', '<=', (int) $f['duration_max_days']))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate($perPage);
    }

    /** A project they may see now — or one they offered on, which stays open to them after the client chose (FL-309). */
    public function find(Freelancer $freelancer, string $uuid): Project
    {
        $project = $this->visible($freelancer)->where('uuid', $uuid)->with(['specialty.translations', 'skills.translations'])->withCount('media')->first();

        // FL-205: a private project they are invited to (and the invitation is still alive).
        $project ??= Project::query()->where('uuid', $uuid)->where('status', Status::Published->value)->where('visibility', 'private')
            ->whereHas('invitations', fn ($q) => $q->where('freelancer_id', $freelancer->id)->whereIn('status', \Modules\Freelance\Models\FreelanceProjectInvitation::LIVE))
            ->with(['specialty.translations', 'skills.translations'])->withCount('media')->first();

        return $project ?? Project::query()->where('uuid', $uuid)->whereIn('status', [Status::Awarded->value, Status::InProgress->value, Status::Closed->value, Status::Published->value, Status::Cancelled->value])
            ->whereHas('offers', fn ($q) => $q->where('freelancer_id', $freelancer->id))
            ->with(['specialty.translations', 'skills.translations'])->withCount('media')->firstOrFail();
    }

    public function favorite(Freelancer $freelancer, Project $project): void
    {
        DB::table('freelance_project_favorites')->insertOrIgnore(['freelancer_id' => $freelancer->id, 'project_id' => $project->id, 'created_at' => now()]);
    }

    public function unfavorite(Freelancer $freelancer, Project $project): void
    {
        $freelancer->projectFavorites()->where('project_id', $project->id)->delete();
    }

    /** @param  iterable<Project>  $projects  @return array<int, true> ids the freelancer already offered on, among these */
    public function offeredIds(Freelancer $freelancer, iterable $projects): array
    {
        $ids = collect($projects)->pluck('id')->all();

        return $ids === [] ? [] : \Modules\Freelance\Models\FreelanceOffer::query()->where('freelancer_id', $freelancer->id)->whereIn('project_id', $ids)->pluck('project_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** @param  iterable<Project>  $projects  @return array<int, true> ids the freelancer saved, among these */
    public function favoriteIds(Freelancer $freelancer, iterable $projects): array
    {
        $ids = collect($projects)->pluck('id')->all();

        return $ids === [] ? [] : $freelancer->projectFavorites()->whereIn('project_id', $ids)->pluck('project_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** @return array<string, mixed> */
    public function present(Project $project, bool $favorite, bool $detail): array
    {
        $out = [
            'uuid' => $project->uuid,
            'title' => $project->title,
            'specialty' => $project->specialty === null ? null : ['id' => $project->specialty->id, 'name' => $project->specialty->translatedName()],
            'skills' => $project->skills->map(fn (FreelanceSkill $s) => ['id' => $s->id, 'name' => $s->translatedName()])->values()->all(),
            'budget' => [
                'type' => $project->budget_type,
                'min_minor' => $project->budget_min_minor,
                'max_minor' => $project->budget_type === 'range' ? $project->budget_max_minor : null,
                'currency' => $project->currency_code,
            ],
            'duration_days' => $project->duration_days,
            'published_at' => $project->published_at?->toIso8601String(),
            'status' => $project->status->value,
            'is_favorite' => $favorite,
            // A project whose window is closed stays in the list (marked), but no offer can be made on it.
            'offers' => app(ProjectOffersWindow::class)->present($project, false),
        ];

        // The list shows a short text; the page shows all of it. Never the client, never the files themselves.
        return $out + ($detail
            ? ['description' => $project->description, 'questions' => array_values((array) $project->questions), 'attachments_count' => (int) $project->media_count]
            : ['summary' => mb_strimwidth((string) $project->description, 0, 160, '…')]);
    }

    /** @return list<int> the specialty and its sub-specialties (picking a parent finds the work of its children too) */
    private function withChildren(int $id): array
    {
        return FreelanceSpecialty::query()->where('parent_id', $id)->pluck('id')->prepend($id)->map(fn ($i) => (int) $i)->all();
    }
}
