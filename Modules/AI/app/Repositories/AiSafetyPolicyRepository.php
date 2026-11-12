<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Illuminate\Support\Collection;
use Modules\AI\Models\AiSafetyPolicy;

/**
 * AI safety policies as a catalog (like CountryRepository): name / description synced from `translations[]`, status
 * toggle, bulk delete, trash / restore / force delete, and the policy picker of the safety rules form.
 */
class AiSafetyPolicyRepository extends TranslatableRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['translations', 'translation'];

    protected array $withCount = ['rules'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'id' => 'asc',
    ];

    /**
     * Moving a policy to the trash is allowed: its rules stop applying (like switching it off) and stay for a
     * restore. Deleting it for good is blocked while it has rules (the database would delete them with it - the trash
     * included) or safety events name it (the log would lose it).
     *
     * @var list<string>
     */
    protected array $deleteBlockRelations = [];

    /** @var list<string> */
    protected array $forceDeleteBlockRelations = ['safetyRules', 'safetyEvents'];

    public function __construct(AiSafetyPolicy $model)
    {
        $this->model = $model;
    }

    /**
     * Policy picker of the safety rules form: every policy not in the trash - switched-off ones too, so a rule of a
     * paused policy still shows it.
     */
    public function dropdown(): Collection
    {
        return $this->query()
            ->orderBy('id')
            ->get()
            ->map(fn (AiSafetyPolicy $policy) => [
                'id' => $policy->id,
                'name' => $policy->name,
                'risk_level' => $policy->risk_level,
                'status' => (bool) $policy->status,
            ])
            ->values();
    }

    /**
     * @param  list<string>  $relations
     */
    public function forceDestroy(int|string $id, array $relations = []): bool
    {
        return parent::forceDestroy($id, $relations === [] ? $this->forceDeleteBlockRelations : $relations);
    }
}
