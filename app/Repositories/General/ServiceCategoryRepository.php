<?php

namespace App\Repositories\General;

use App\Repositories\TranslatableRepository;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServiceCategoryRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation', 'parent.translations', 'parent.translation'];

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

    /**
     * Top-level categories with their children eager-loaded, for the admin tree view.
     *
     * @return EloquentCollection<int, ServiceCategory>
     */
    public function tree(): EloquentCollection
    {
        return $this->model->newQuery()
            ->with(['translations', 'translation', 'children.translations', 'children.translation'])
            ->whereNull('parent_id')
            ->with(['children' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Leaf categories only (no children) - the only ones a provider is allowed to be linked to.
     *
     * @return EloquentCollection<int, ServiceCategory>
     */
    public function leafOptions(): EloquentCollection
    {
        return $this->model->newQuery()
            ->with(['translations', 'translation'])
            ->whereDoesntHave('children')
            ->where('status', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * All active categories (parents included), for the parent_id select on the create/edit form.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function dropdown(): Collection
    {
        return $this->model->newQuery()
            ->with(['translations', 'translation'])
            ->where('status', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ServiceCategory $category) => [
                'id' => $category->id,
                'name' => $category->translatedName(),
                'parent_id' => $category->parent_id,
                'translations' => $category->translations->map(fn ($item) => [
                    'locale' => $item->locale,
                    'name' => $item->name,
                ])->values(),
            ])
            ->values();
    }
}
