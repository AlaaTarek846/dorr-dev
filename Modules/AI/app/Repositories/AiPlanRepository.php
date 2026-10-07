<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiPlan;

class AiPlanRepository extends BaseRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'sort_order' => 'asc',
        'id' => 'asc',
    ];

    /**
     * Avoids an N+1 currency lookup per row in the admin list/show screens
     * now that currency is a real relation (currency_id -> currencies)
     * instead of a free string column - AiPlan::getCurrencyAttribute()
     * reads $this->currencyRef on every row.
     */
    protected array $with = ['currencyRef'];

    public function __construct(AiPlan $model)
    {
        $this->model = $model;
    }
}
