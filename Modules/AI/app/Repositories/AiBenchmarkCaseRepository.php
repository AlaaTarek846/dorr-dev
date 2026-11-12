<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiBenchmarkCase;

/**
 * AI benchmark cases as a catalog list (like countries, without translations - a case holds test data, not labels):
 * status toggle, bulk delete, trash / restore / force delete. Deleting a case for good is blocked while past runs
 * have results for it (the database would delete them with it).
 */
class AiBenchmarkCaseRepository extends BaseRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
        'id' => 'desc',
    ];

    protected array $withCount = ['results'];

    /** Moving a case to the trash is always allowed: it stops running (like switching it off) and keeps its results. */
    protected array $deleteBlockRelations = [];

    /** @var list<string> */
    protected array $forceDeleteBlockRelations = ['results'];

    public function __construct(AiBenchmarkCase $model)
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
