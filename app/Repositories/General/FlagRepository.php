<?php

namespace App\Repositories\General;

use App\Models\Flag;
use App\Repositories\TranslatableRepository;

class FlagRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation'];

    protected array $deleteBlockRelations = ['languages', 'countries'];

    public function __construct(Flag $model)
    {
        $this->model = $model;
    }
}
