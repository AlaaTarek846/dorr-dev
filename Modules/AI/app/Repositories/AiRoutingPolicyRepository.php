<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Illuminate\Support\Collection;
use Modules\AI\Models\AiRoutingPolicy;

/**
 * AI routing policies as a catalog (like CountryRepository): translations synced from `translations[]`, status
 * toggle, bulk delete, trash / restore / force delete.
 */
class AiRoutingPolicyRepository extends TranslatableRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['plan', 'translations', 'translation'];

    protected array $withCount = ['rules'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'priority' => 'desc',
        'id' => 'asc',
    ];

    /**
     * Moving a policy to the trash is allowed: the engine stops using it (by scope and as a gateway default), like
     * switching it off; its rules stay for a restore. Deleting it for good is blocked while it has rules (the
     * database would delete them with it) or a gateway uses it as its default - both in the trash too.
     *
     * @var list<string>
     */
    protected array $deleteBlockRelations = [];

    /** @var list<string> */
    protected array $forceDeleteBlockRelations = ['routingRules', 'gateways'];

    public function __construct(AiRoutingPolicy $model)
    {
        $this->model = $model;
    }

    /**
     * Policy picker of the gateways and routing rules forms: every policy not in the trash - switched-off ones too,
     * so a gateway / rule tied to a paused policy still shows it.
     */
    public function dropdown(): Collection
    {
        return $this->query()
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (AiRoutingPolicy $policy) => [
                'id' => $policy->id,
                'name' => $policy->name,
                'scope_type' => $policy->scope_type,
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
