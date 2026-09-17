<?php

namespace App\Repositories\General;

use App\Models\ServiceCategory;
use App\Repositories\TranslatableRepository;
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
     * When `?parent_id=null` is passed, only top-level categories (parents) are returned.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function dropdown(): Collection
    {
        $query = $this->model->newQuery()
            ->with(['translations', 'translation'])
            ->where('status', true);

        if (request()->query('parent_id') === 'null') {
            $query->whereNull('parent_id');
        }

        return $query->orderBy('sort_order')
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

    /**
     * Full hierarchy of active categories with nested children loaded, for the
     * provider TreeSelect. Only leaf categories (no children) are selectable.
     *
     * @return EloquentCollection<int, ServiceCategory>
     */
    public function treeOptions(): EloquentCollection
    {
        $categories = $this->model->newQuery()
            ->with(['translations', 'translation'])
            ->where('status', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $children = $categories->groupBy('parent_id');
        $roots = $categories->filter(fn (ServiceCategory $category) => $category->parent_id === null);

        return $this->nestTree($roots->values(), $children);
    }

    /**
     * @param  Collection<int, ServiceCategory>  $nodes
     * @param  Collection<int, Collection<int, ServiceCategory>>  $children
     * @return EloquentCollection<int, ServiceCategory>
     */
    protected function nestTree(Collection $nodes, Collection $children): EloquentCollection
    {
        return EloquentCollection::make($nodes->map(function (ServiceCategory $category) use ($children) {
            $nested = $children->get($category->id, collect());
            $category->setRelation('children', $this->nestTree($nested, $children));

            return $category;
        })->values());
    }
}
