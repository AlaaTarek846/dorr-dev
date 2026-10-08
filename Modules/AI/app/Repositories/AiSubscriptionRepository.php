<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiSubscription;

class AiSubscriptionRepository extends BaseRepository
{
    /**
     * @var list<string>
     */
    protected array $with = ['owner', 'plan'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'id' => 'desc',
    ];

    public function __construct(AiSubscription $model)
    {
        $this->model = $model;
    }
}
