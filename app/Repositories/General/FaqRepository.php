<?php

namespace App\Repositories\General;

use App\Models\Faq;
use App\Repositories\TranslatableRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class FaqRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation', 'service.translations', 'service.translation'];

    protected array $orderBy = [
        'sort_order' => 'asc',
        'id' => 'asc',
    ];

    public function __construct(Faq $model)
    {
        $this->model = $model;
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
