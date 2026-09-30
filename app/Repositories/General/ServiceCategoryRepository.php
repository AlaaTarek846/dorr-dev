<?php

namespace App\Repositories\General;

use App\Enums\ServiceAudience;
use App\Models\ServiceCategory;
use App\Repositories\TranslatableRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceCategoryRepository extends TranslatableRepository
{
    /**
     * @var list<string>
     */
    protected array $deleteBlockRelations = ['children'];

    protected array $with = ['translations', 'translation', 'parent.translations', 'parent.translation'];

    protected array $orderBy = [
        'sort_order' => 'asc',
        'id' => 'asc',
    ];

    public function __construct(ServiceCategory $model)
    {
        $this->model = $model;
    }

    public function nextSortOrder(?int $parentId): int
    {
        $query = $this->model->newQuery();

        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', $parentId);
        }

        $max = $query->max('sort_order');

        return ((int) $max) + 1;
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorderSiblings(?int $parentId, array $orderedIds): void
    {
        DB::transaction(function () use ($parentId, $orderedIds): void {
            $siblingQuery = $this->model->newQuery();

            if ($parentId === null) {
                $siblingQuery->whereNull('parent_id');
            } else {
                $siblingQuery->where('parent_id', $parentId);
            }

            $expectedIds = $siblingQuery
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();

            $orderedIds = array_values(array_unique(array_map(static fn ($id) => (int) $id, $orderedIds)));

            if ($expectedIds === [] && $orderedIds === []) {
                return;
            }

            $normalizedExpected = $expectedIds;
            $normalizedOrdered = $orderedIds;
            sort($normalizedExpected);
            sort($normalizedOrdered);

            if ($normalizedExpected !== $normalizedOrdered) {
                throw ValidationException::withMessages([
                    'ordered_ids' => [__('validation.custom.service_categories.reorder_siblings')],
                ]);
            }

            foreach ($orderedIds as $index => $id) {
                $belongs = $this->model->newQuery()
                    ->whereKey($id)
                    ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'))
                    ->when($parentId !== null, fn ($query) => $query->where('parent_id', $parentId))
                    ->exists();

                if (! $belongs) {
                    throw ValidationException::withMessages([
                        'ordered_ids' => [__('validation.custom.service_categories.reorder_siblings')],
                    ]);
                }

                $this->model->newQuery()
                    ->whereKey($id)
                    ->update(['sort_order' => $index + 1]);
            }
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
     * Active top-level categories flagged for the app home (is_login_dashboard),
     * each with its active children — what the customer-facing home lists.
     *
     * @return EloquentCollection<int, ServiceCategory>
     */
    public function publicServices(string $audience = 'user', bool $homeOnly = false): EloquentCollection
    {
        if (! in_array($audience, ServiceAudience::values(), true)) {
            $audience = ServiceAudience::User->value;
        }

        return $this->model->newQuery()
            ->with([
                'translations',
                'translation',
                'children' => fn ($query) => $query
                    ->where('status', true)
                    ->whereJsonContains('audiences', $audience)
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'children.translations',
                'children.translation',
            ])
            ->whereNull('parent_id')
            ->where('status', true)
            ->whereJsonContains('audiences', $audience)
            ->when($homeOnly, fn ($query) => $query->where('is_login_dashboard', true))
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
            ->whereJsonContains('audiences', ServiceAudience::Provider->value)
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
                'module_name' => $category->module_name,
                'image' => $category->getSingleMediaUrl('image') ?: null,
                'audiences' => is_array($category->audiences) ? $category->audiences : [],
                'translations' => $category->translations->map(fn ($item) => [
                    'locale' => $item->locale,
                    'name' => $item->name,
                    'description' => $item->description,
                ])->values(),
            ])
            ->values();
    }

    protected function syncTranslations(Model $model, array $data): void
    {
        if (! isset($data['translations']) || ! method_exists($model, 'translations')) {
            return;
        }

        $allowedLocales = $this->shouldFilterTranslationsByStorableLocales()
            ? $this->storableTranslationLocales()
            : null;

        foreach ($data['translations'] as $translation) {
            if (! isset($translation['locale'], $translation['name'])) {
                continue;
            }

            $locale = strtolower((string) $translation['locale']);

            if ($allowedLocales !== null && ! in_array($locale, $allowedLocales, true)) {
                continue;
            }

            $payload = ['name' => $translation['name']];

            if (array_key_exists('description', $translation)) {
                $payload['description'] = $translation['description'];
            }

            $model->translations()->updateOrCreate(
                ['locale' => $locale],
                $payload,
            );
        }

        if ($allowedLocales !== null) {
            if ($allowedLocales === []) {
                $model->translations()->delete();
            } else {
                $model->translations()->whereNotIn('locale', $allowedLocales)->delete();
            }
        }
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
