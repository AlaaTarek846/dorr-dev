<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Illuminate\Support\Collection;
use Modules\AI\Models\AiPlan;

/**
 * AI plans as a catalog (like CountryRepository): translations synced from `translations[]`, status toggle,
 * bulk delete, trash / restore / force delete.
 */
class AiPlanRepository extends TranslatableRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'sort_order' => 'asc',
        'id' => 'asc',
    ];

    /**
     * currencyRef: avoids an N+1 currency lookup per row (AiPlan::getCurrencyAttribute() reads it on every row).
     */
    protected array $with = ['translations', 'translation', 'currencyRef'];

    /**
     * Moving a plan to the trash is allowed even with subscribers: it stops new subscriptions and renewals
     * (AiPlan::isAvailable()) while current subscribers keep it until their period ends. Deleting it for good
     * is blocked while anything still points at it.
     *
     * @var list<string>
     */
    protected array $deleteBlockRelations = [];

    /** @var list<string> */
    protected array $forceDeleteBlockRelations = ['subscriptions'];

    public function __construct(AiPlan $model)
    {
        $this->model = $model;
    }

    /**
     * Plan picker for other AI screens (routing policies): every plan not in the trash - inactive ones too, so a
     * policy tied to a paused plan still shows it.
     */
    public function dropdown(): Collection
    {
        return $this->query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (AiPlan $plan) => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'status' => (bool) $plan->status,
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
