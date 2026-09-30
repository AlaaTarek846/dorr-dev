<?php

namespace App\Repositories\General;

use App\Models\LegalPage;
use App\Repositories\TranslatableRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class LegalPageRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation', 'service.translations', 'service.translation'];

    protected array $orderBy = [
        'id' => 'asc',
    ];

    public function __construct(LegalPage $model)
    {
        $this->model = $model;
    }

    protected function buildIndexQuery(): Builder
    {
        $query = parent::buildIndexQuery();

        if (request()->filled('type')) {
            $query->where('type', request('type'));
        }

        return $query;
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
                'name' => ucfirst($item->type).' Page #'.$item->id,
            ])
            ->values();
    }

    /**
     * The active legal page of a given type for a service (or the general one
     * when no service is requested) — what the mobile apps show.
     */
    public function activeForType(string $type, ?int $serviceId = null): ?LegalPage
    {
        return $this->model->newQuery()
            ->with(['translations', 'translation'])
            ->where('type', $type)
            ->where('status', true)
            ->when($serviceId === null, fn (Builder $query) => $query->whereNull('service_id'))
            ->when($serviceId !== null, fn (Builder $query) => $query->where('service_id', $serviceId))
            ->orderBy('id')
            ->first();
    }
}