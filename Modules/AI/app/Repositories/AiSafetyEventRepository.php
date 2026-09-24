<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiSafetyEvent;

class AiSafetyEventRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['owner', 'policy', 'rule'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiSafetyEvent $model)
    {
        $this->model = $model;
    }
}
