<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiRoutingRuleResource;
use Modules\AI\Repositories\AiRoutingRuleRepository;

class AiRoutingRuleService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiRoutingRuleResource::class;

    public function __construct(AiRoutingRuleRepository $repository)
    {
        parent::__construct($repository);
    }
}
