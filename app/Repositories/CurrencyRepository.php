<?php

namespace App\Repositories;

use App\Models\Currency;

class CurrencyRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation'];

    protected array $deleteBlockRelations = ['countries'];

    public function __construct(Currency $model)
    {
        $this->model = $model;
    }
}
