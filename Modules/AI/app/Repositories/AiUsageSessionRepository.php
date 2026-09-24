<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiUsageSession;

class AiUsageSessionRepository extends BaseRepository
{
    /**
     * @var list<string>
     */
    protected array $with = ['owner', 'subscription.plan'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'started_at' => 'desc',
    ];

    public function __construct(AiUsageSession $model)
    {
        $this->model = $model;
    }
}
