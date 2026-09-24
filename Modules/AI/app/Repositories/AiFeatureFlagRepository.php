<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiFeatureFlag;

class AiFeatureFlagRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['provider'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiFeatureFlag $model)
    {
        $this->model = $model;
    }
}
