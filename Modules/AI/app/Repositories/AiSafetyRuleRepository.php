<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Modules\AI\Models\AiSafetyRule;

/**
 * AI safety rules as a catalog (like CountryRepository): the name synced from `translations[]`, status toggle, bulk
 * delete, trash / restore / force delete.
 */
class AiSafetyRuleRepository extends TranslatableRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['policy', 'translations', 'translation'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'priority' => 'desc',
        'id' => 'asc',
    ];

    /**
     * Moving a rule to the trash is allowed: the guard stops applying it (like switching it off). Deleting it for
     * good is blocked while safety events name it (the log would lose it).
     *
     * @var list<string>
     */
    protected array $deleteBlockRelations = [];

    /** @var list<string> */
    protected array $forceDeleteBlockRelations = ['safetyEvents'];

    public function __construct(AiSafetyRule $model)
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
