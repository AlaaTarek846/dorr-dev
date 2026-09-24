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

    public function __construct(AiPlan $model)
    {
        $this->model = $model;
    }
}
