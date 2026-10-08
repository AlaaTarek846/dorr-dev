<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiUsage;

class AiUsageRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['request.owner'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiUsage $model)
    {
        $this->model = $model;
    }
}
