<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiSafetyRule;

class AiSafetyRuleRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['policy'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'priority' => 'desc',
        'id' => 'asc',
    ];

    public function __construct(AiSafetyRule $model)
    {
        $this->model = $model;
    }
}
