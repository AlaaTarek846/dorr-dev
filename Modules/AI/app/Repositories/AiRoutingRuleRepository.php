<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiRoutingRule;

class AiRoutingRuleRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['policy', 'intent', 'provider'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'priority' => 'desc',
        'id' => 'asc',
    ];

    public function __construct(AiRoutingRule $model)
    {
        $this->model = $model;
    }
}
