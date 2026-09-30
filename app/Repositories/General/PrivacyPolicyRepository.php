<?php

namespace App\Repositories\General;

use App\Models\PrivacyPolicy;
use App\Repositories\TranslatableRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class PrivacyPolicyRepository extends TranslatableRepository
{
    protected array $with = ['translations', 'translation', 'service.translations', 'service.translation'];

    protected array $orderBy = [
        'sort_order' => 'asc',
        'id' => 'asc',
    ];

    public function __construct(PrivacyPolicy $model)
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
                'name' => 'Privacy Policy #'.$item->id,
            ])
            ->values();
    }

    /**
     * The active general privacy policy (not tied to any service) for the mobile app.
     */
    public function generalActive(): ?PrivacyPolicy
    {
        return $this->model->newQuery()
            ->with(['translations', 'translation'])
            ->whereNull('service_id')
            ->where('status', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }
}
