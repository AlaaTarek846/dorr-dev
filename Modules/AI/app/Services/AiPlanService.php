<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiPlanResource;
use Modules\AI\Repositories\AiPlanRepository;

class AiPlanService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiPlanResource::class;

    public function __construct(AiPlanRepository $repository)
    {
        parent::__construct($repository);
    }
}
