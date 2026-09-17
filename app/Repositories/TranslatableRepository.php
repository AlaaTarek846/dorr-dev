<?php

namespace App\Repositories;

use App\Repositories\Concerns\SyncsTranslations;

abstract class TranslatableRepository extends BaseRepository
{
    use SyncsTranslations;

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
