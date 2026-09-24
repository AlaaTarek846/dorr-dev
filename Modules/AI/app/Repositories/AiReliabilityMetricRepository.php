<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiReliabilityMetric;

class AiReliabilityMetricRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['provider', 'intent', 'plan'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'period_start' => 'desc',
    ];

    public function __construct(AiReliabilityMetric $model)
    {
        $this->model = $model;
    }
}
