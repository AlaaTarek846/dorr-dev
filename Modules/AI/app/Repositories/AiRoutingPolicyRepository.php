<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiRoutingPolicy;

class AiRoutingPolicyRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['plan'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'priority' => 'desc',
        'id' => 'asc',
    ];

    public function __construct(AiRoutingPolicy $model)
    {
        $this->model = $model;
    }
}
