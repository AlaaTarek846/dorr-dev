<?php

namespace App\Repositories;

use App\Repositories\Concerns\ManagesBulkAndStatus;
use App\Repositories\Concerns\SyncsTranslations;

abstract class TranslatableRepository extends BaseRepository
{
    use ManagesBulkAndStatus, SyncsTranslations;

    /**
     * Relations that block delete.
     *
     * @var list<string>
     */
    protected array $deleteBlockRelations = [];

    /**
     * @param  list<string>  $relations
     */
    public function destroy(int|string $id, array $relations = []): bool
    {
        if ($relations === []) {
            $relations = $this->deleteBlockRelations;
        }

        return parent::destroy($id, $relations);
    }
}
