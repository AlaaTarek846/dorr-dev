<?php

namespace App\Repositories\General;

use App\Repositories\TranslatableRepository;
use App\Models\Currency;

class CurrencyRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation'];

    protected array $deleteBlockRelations = ['countries'];

    public function __construct(Currency $model)
    {
        $this->model = $model;
    }

    public function defaultCurrency(): ?Currency
    {
        return $this->model->newQuery()
            ->where('is_default', true)
            ->first()
            ?? $this->model->newQuery()->orderBy('id')->first();
    }
}
