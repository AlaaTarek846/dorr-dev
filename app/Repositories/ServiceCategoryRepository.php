<?php

namespace App\Repositories;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServiceCategoryRepository extends BaseRepository
{
    protected array $with = ['parent'];

    protected array $orderBy = [
        'sort_order' => 'asc',
        'id' => 'asc',
    ];

    public function __construct(ServiceCategory $model)
    {
        $this->model = $model;
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function deleteMultiple(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            $deleted = 0;

            foreach ($ids as $id) {
                if ($this->destroy($id, ['children'])) {
                    $deleted++;
                }
            }

            return $deleted;
        });
    }

    public function changeStatus(int|string $id, bool $status): ServiceCategory
    {
        $category = $this->query()->findOrFail($id);
        $category->update(['status' => $status]);

        return $this->refresh($category);
    }

    /**
     * Top-level categories with their children eager-loaded, for the admin
     * tree view.
     *
     * @return EloquentCollection<int, ServiceCategory>
     */
    public function tree(): EloquentCollection
    {
        return $this->model->newQuery()
            ->whereNull('parent_id')
            ->with(['children' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Leaf categories only (no children) - the only ones a provider is
     * allowed to be linked to.
     *
     * @return EloquentCollection<int, ServiceCategory>
     */
    public function leafOptions(): EloquentCollection
    {
        return $this->model->newQuery()
            ->whereDoesntHave('children')
            ->where('status', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * All active categories (parents included), for the `parent_id` select
     * on the create/edit form.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function dropdown(): Collection
    {
        return $this->model->newQuery()
            ->where('status', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ServiceCategory $category) => [
                'id' => $category->id,
                'name' => $category->name_ar,
                'name_en' => $category->name_en,
            ])
            ->values();
    }
}
