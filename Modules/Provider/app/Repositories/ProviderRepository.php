<?php

namespace Modules\Provider\Repositories;

use App\Models\ServiceCategory;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Enums\UserStatus;
use Modules\Provider\Models\Provider;

class ProviderRepository extends BaseRepository
{
    protected array $with = [
        'country.flag',
        'services.category.translations',
        'services.category.translation',
    ];

    protected array $orderBy = [
        'id' => 'desc',
    ];

    public function __construct(Provider $model)
    {
        $this->model = $model;
    }

    public function changeStatus(int|string $id, bool $status): Provider
    {
        return $this->updateUserStatus(
            $id,
            $status ? UserStatus::Active : UserStatus::Inactive,
        );
    }

    public function updateUserStatus(int|string $id, UserStatus $status): Provider
    {
        $provider = $this->query()->findOrFail($id);
        $provider->update(['status' => $status]);

        return $this->refresh($provider);
    }

    /**
     * @return list<string>
     */
    protected function reservedPayloadKeys(): array
    {
        return array_merge(parent::reservedPayloadKeys(), [
            'service_category_ids',
            'remove_avatar',
        ]);
    }

    protected function afterStore(Model $model, array $data): void
    {
        parent::afterStore($model, $data);

        if (array_key_exists('service_category_ids', $data)) {
            $this->syncServiceCategories($model, $data['service_category_ids'] ?? []);
        }
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        parent::afterUpdate($model, $data);

        if (array_key_exists('service_category_ids', $data)) {
            $this->syncServiceCategories($model, $data['service_category_ids'] ?? []);
        }

        if (! empty($data['remove_avatar']) && method_exists($model, 'deleteSingleMedia')) {
            $model->deleteSingleMedia('avatar');
        }
    }

    /**
     * @param  list<int|string>|null  $categoryIds
     */
    protected function syncServiceCategories(Provider $provider, ?array $categoryIds): void
    {
        $categoryIds = collect($categoryIds ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($categoryIds === []) {
            $provider->services()->delete();

            return;
        }

        $leafIds = ServiceCategory::query()
            ->whereIn('id', $categoryIds)
            ->whereDoesntHave('children')
            ->where('status', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $provider->services()->whereNotIn('service_category_id', $leafIds)->delete();

        $existing = $provider->services()->pluck('service_category_id')->map(fn ($id) => (int) $id)->all();

        foreach ($leafIds as $categoryId) {
            if (! in_array($categoryId, $existing, true)) {
                $provider->services()->create(['service_category_id' => $categoryId]);
            }
        }
    }
}
