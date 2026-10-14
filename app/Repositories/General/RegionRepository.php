<?php

namespace App\Repositories\General;

use App\Models\Region;
use App\Repositories\TranslatableRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RegionRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation', 'country.translation'];

    public function __construct(Region $model)
    {
        $this->model = $model;
    }

    /** The list can be narrowed to one country (`?country_id=`). */
    protected function applyIndexDefaults(Builder $query): Builder
    {
        $countryId = request()->integer('country_id');

        return parent::applyIndexDefaults($query->when($countryId > 0, fn ($q) => $q->where('country_id', $countryId)));
    }

    /** Active regions, optionally of one country (`?country_id=`) — for pickers. */
    public function dropdown(): Collection
    {
        $countryId = request()->integer('country_id');

        return $this->model->newQuery()
            ->with(['translations', 'translation'])
            ->where('status', true)
            ->when($countryId > 0, fn ($q) => $q->where('country_id', $countryId))
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (Region $region) => [
                'id' => $region->id,
                'country_id' => $region->country_id,
                'name' => $region->translatedName() ?? '#'.$region->id,
            ])
            ->values();
    }
}
