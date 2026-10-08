<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiReliabilityMetricResource;
use Modules\AI\Repositories\AiReliabilityMetricRepository;

class AiReliabilityMetricService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiReliabilityMetricResource::class;

    public function __construct(AiReliabilityMetricRepository $repository)
    {
        parent::__construct($repository);
    }
}
