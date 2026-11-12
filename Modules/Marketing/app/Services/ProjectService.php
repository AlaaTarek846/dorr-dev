<?php

namespace Modules\Marketing\Services;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Marketing\Enums\ProjectRole;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingProjectMember;
use Modules\Marketing\Services\Features\MarketingCountry;
use Modules\Marketing\Services\Packages\MarketingEntitlement;

/** Projects (U01): the caller sees the ones they are on the team of; the owner's package caps how many are active. */
class ProjectService
{
    public function __construct(
        private readonly MarketingAccess $access,
        private readonly MarketingAudit $audit,
        private readonly MarketingEntitlement $entitlement,
    ) {}

    public function list(Model $actor, ?string $status, ?string $q, int $perPage): LengthAwarePaginator
    {
        return MarketingProject::query()
            ->whereIn('id', MarketingProjectMember::query()->where('member_type', $actor->getMorphClass())->where('member_id', $actor->getKey())->select('project_id'))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($q !== null && $q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', '%'.$q.'%')->orWhere('industry', 'like', '%'.$q.'%')))
            ->with(['profile', 'members'])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @param  array<string, mixed>  $data */
    public function create(Model $actor, array $data): MarketingProject
    {
        $this->entitlement->assertRoom($actor, 'projects');
        $country = Country::query()->with('currency')->findOrFail(MarketingCountry::of($actor));

        return DB::transaction(function () use ($actor, $data, $country) {
            $project = MarketingProject::query()->create([
                'owner_type' => $actor->getMorphClass(),
                'owner_id' => $actor->getKey(),
                'country_id' => $country->id,
                'city_names' => array_values($data['cities'] ?? []),
                'name' => $data['name'],
                'industry' => $data['industry'],
                'stage' => $data['stage'] ?? 'idea',
                'goals' => array_values($data['goals'] ?? []),
                'budget_currency' => (string) ($country->currency?->code ?? ''),
                'status' => MarketingProject::ACTIVE,
                'lock_version' => 1,
            ]);
            $project->profile()->create(['discovery' => ['questions' => [], 'answers' => [], 'extra' => []]]);
            $project->members()->create(['member_type' => $actor->getMorphClass(), 'member_id' => $actor->getKey(), 'role' => ProjectRole::Owner]);
            $this->audit->record($project, $actor, 'project.created', $project, after: $this->snapshot($project));

            return $project->load(['profile', 'members']);
        });
    }

    /**
     * Optimistic edit: `lock_version` must be the one the caller read, or nothing changes (409).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(MarketingProject $project, Model $actor, array $data): MarketingProject
    {
        $this->access->assert($project, $actor, 'edit');

        return DB::transaction(function () use ($project, $actor, $data) {
            $before = $this->snapshot($project);
            $changes = collect($data)->only(['name', 'industry', 'stage', 'goals'])->all();
            if (array_key_exists('cities', $data)) {
                $changes['city_names'] = array_values($data['cities'] ?? []);
            }

            $updated = MarketingProject::query()->whereKey($project->id)->where('lock_version', (int) $data['lock_version'])
                ->update(array_map(fn ($v) => is_array($v) ? json_encode(array_values($v), JSON_UNESCAPED_UNICODE) : $v, $changes) + [
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_at' => now(),
                ]);
            if ($updated === 0) {
                throw new MarketingException('version_conflict', 409, [], ['lock_version' => $project->fresh()->lock_version]);
            }

            $project->refresh();
            $this->audit->record($project, $actor, 'project.updated', $project, $before, $this->snapshot($project));

            return $project->load(['profile', 'members']);
        });
    }

    public function archive(MarketingProject $project, Model $actor, bool $archived): MarketingProject
    {
        $this->access->assert($project, $actor, 'owner');
        if (! $archived && $project->status === MarketingProject::ARCHIVED) {
            $this->entitlement->assertRoom($actor, 'projects');
        }
        $project->update(['status' => $archived ? MarketingProject::ARCHIVED : MarketingProject::ACTIVE, 'lock_version' => $project->lock_version + 1]);
        $this->audit->record($project, $actor, $archived ? 'project.archived' : 'project.restored', $project);

        return $project->load(['profile', 'members']);
    }

    public function delete(MarketingProject $project, Model $actor): void
    {
        $this->access->assert($project, $actor, 'owner');
        $this->audit->record($project, $actor, 'project.deleted', $project, $this->snapshot($project));
        $project->delete();
    }

    /** @return array<string, mixed> */
    public function snapshot(MarketingProject $project): array
    {
        return $project->only(['name', 'industry', 'stage', 'goals', 'city_names', 'status']);
    }
}
