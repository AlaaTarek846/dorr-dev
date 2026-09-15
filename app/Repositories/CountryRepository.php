<?php

namespace App\Repositories;

use App\Models\Country;
use Illuminate\Support\Collection;

class CountryRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation', 'flag', 'currency'];

    protected array $deleteBlockRelations = ['admins'];

    public function __construct(Country $model)
    {
        $this->model = $model;
    }

    public function dropdown(): Collection
    {
        return $this->index()
            ->where('status', true)
            ->get()
            ->map(fn (Country $country) => [
                'id' => $country->id,
                'code' => $country->code,
                'name' => $country->translatedName() ?? $country->code,
                'dial_code' => $country->dial_code,
                'flag' => $country->flag ? [
                    'id' => $country->flag->id,
                    'code' => $country->flag->code,
                ] : null,
            ])
            ->values();
    }
}
