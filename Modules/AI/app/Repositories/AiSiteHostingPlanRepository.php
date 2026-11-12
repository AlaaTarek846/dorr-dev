<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Modules\AI\Models\AiSiteHostingPlan;

/**
 * Site hosting plans as a catalog (like CountryRepository): translations synced from `translations[]`, status
 * toggle, bulk delete, trash / restore / force delete.
 */
class AiSiteHostingPlanRepository extends TranslatableRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'sort_order' => 'asc',
        'id' => 'asc',
    ];

    protected array $with = ['translations', 'translation'];

    protected array $withCount = ['prices'];

    /**
     * Moving a plan to the trash is allowed even with hosted sites on it: it stops new subscriptions and renewals
     * (AiSiteHostingPlan::isAvailable()) while every site keeps running until its paid period ends. Deleting it
     * for good is blocked while any hosting still points at it.
     *
     * @var list<string>
     */
    protected array $deleteBlockRelations = [];

    /** @var list<string> */
    protected array $forceDeleteBlockRelations = ['hostings'];

    public function __construct(AiSiteHostingPlan $model)
    {
        $this->model = $model;
    }

    /**
     * @param  list<string>  $relations
     */
    public function forceDestroy(int|string $id, array $relations = []): bool
    {
        return parent::forceDestroy($id, $relations === [] ? $this->forceDeleteBlockRelations : $relations);
    }
}
