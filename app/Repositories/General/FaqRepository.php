<?php

namespace App\Repositories\General;

use App\Models\Faq;
use App\Repositories\TranslatableRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FaqRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation', 'service.translations', 'service.translation'];

    protected array $orderBy = [
        'id' => 'desc',
    ];

    public function __construct(Faq $model)
    {
        $this->model = $model;
    }

    /**
     * FAQs sharing one ordering group: the general ones (null) or those of a single service.
     */
    protected function groupQuery(?int $serviceId): Builder
    {
        return $this->model->newQuery()
            ->when($serviceId === null, fn (Builder $query) => $query->whereNull('service_id'))
            ->when($serviceId !== null, fn (Builder $query) => $query->where('service_id', $serviceId));
    }

    public function nextSortOrder(?int $serviceId): int
    {
        return ((int) $this->groupQuery($serviceId)->max('sort_order')) + 1;
    }

    /**
     * @return EloquentCollection<int, Faq>
     */
    public function orderedForService(?int $serviceId): EloquentCollection
    {
        return $this->groupQuery($serviceId)
            ->with(['translations', 'translation'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorderGroup(?int $serviceId, array $orderedIds): void
    {
        DB::transaction(function () use ($serviceId, $orderedIds): void {
            $expectedIds = $this->groupQuery($serviceId)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            $orderedIds = array_values(array_unique(array_map(static fn ($id) => (int) $id, $orderedIds)));
            $normalizedOrdered = $orderedIds;
            sort($normalizedOrdered);

            if ($expectedIds !== $normalizedOrdered) {
                throw ValidationException::withMessages([
                    'ordered_ids' => [__('validation.custom.faqs.reorder_group')],
                ]);
            }

            foreach ($orderedIds as $index => $id) {
                $this->model->newQuery()
                    ->whereKey($id)
                    ->update(['sort_order' => $index + 1]);
            }
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function dropdown(): Collection
    {
        return $this->index()
            ->where('status', true)
            ->get()
            ->map(fn (Model $item) => [
                'id' => $item->id,
                'name' => $item->translated('question'),
            ])
            ->values();
    }

    /**
     * Active general FAQs (not tied to any service) for the mobile app, in display order.
     *
     * @return EloquentCollection<int, Faq>
     */
    public function generalActive(): EloquentCollection
    {
        return $this->model->newQuery()
            ->with(['translations', 'translation'])
            ->whereNull('service_id')
            ->where('status', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
