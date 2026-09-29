<?php

namespace Modules\Wallet\Repositories;

use App\Repositories\TranslatableRepository;
use Illuminate\Support\Collection;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Repositories\Concerns\SyncsNameAndDescription;

class PaymentMethodRepository extends TranslatableRepository
{
    use SyncsNameAndDescription;

    /**
     * @var list<string>
     */
    protected array $with = ['translations', 'translation', 'countryLinks.country'];

    protected array $orderBy = ['sort_order' => 'asc', 'id' => 'desc'];

    public function __construct(PaymentMethod $model)
    {
        $this->model = $model;
    }

    public function dropdown(): Collection
    {
        return $this->index()
            ->where('status', true)
            ->get()
            ->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'code' => $method->code,
                'name' => $method->translatedName() ?? $method->code,
            ])
            ->values();
    }
}
