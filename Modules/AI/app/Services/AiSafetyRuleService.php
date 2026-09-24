<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiSafetyRuleResource;
use Modules\AI\Repositories\AiSafetyRuleRepository;

class AiSafetyRuleService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiSafetyRuleResource::class;

    public function __construct(AiSafetyRuleRepository $repository)
    {
        parent::__construct($repository);
    }
}
