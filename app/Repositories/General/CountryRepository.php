<?php

namespace App\Repositories\General;

use App\Models\Country;
use App\Repositories\TranslatableRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class CountryRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation', 'flag', 'currency', 'serviceCategories'];

    protected array $deleteBlockRelations = ['admins'];

    public function __construct(Country $model)
    {
        $this->model = $model;
    }

    /**
     * @return list<string>
     */
    protected function reservedPayloadKeys(): array
    {
        return array_merge(parent::reservedPayloadKeys(), ['service_ids']);
    }

    protected function afterStore(Model $model, array $data): void
    {
        parent::afterStore($model, $data);
        $this->syncServiceCategories($model, $data);
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        parent::afterUpdate($model, $data);
        $this->syncServiceCategories($model, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncServiceCategories(Model $model, array $data): void
    {
        if (! array_key_exists('service_ids', $data) || ! $model instanceof Country) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $data['service_ids'] ?? [])));
        $model->serviceCategories()->sync($ids);
    }

    public function dropdown(): Collection
    {
        return $this->model->newQuery()
            ->with(['translations', 'translation', 'flag'])
            ->where('status', true)
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn (Country $country) => [
                'id' => $country->id,
                'code' => $country->code,
                'name' => $country->translatedName() ?? $country->code,
                'dial_code' => $country->dial_code,
                'phone_length' => $country->phone_length,
                'phone_starts_with' => $country->phone_starts_with,
                'is_default' => (bool) $country->is_default,
                'flag' => $country->flag ? [
                    'id' => $country->flag->id,
                    'code' => $country->flag->code,
                ] : null,
            ])
            ->values();
    }
}
