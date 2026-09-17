<?php

namespace App\Repositories\General;

use App\Repositories\TranslatableRepository;
use App\Models\Flag;

class FlagRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation'];

    protected array $deleteBlockRelations = ['languages', 'countries'];

    public function __construct(Flag $model)
    {
        $this->model = $model;
    }
}
