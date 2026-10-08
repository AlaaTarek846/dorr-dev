<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiProviderDataRuleResource;
use Modules\AI\Repositories\AiProviderDataRuleRepository;

class AiProviderDataRuleService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiProviderDataRuleResource::class;

    public function __construct(AiProviderDataRuleRepository $repository)
    {
        parent::__construct($repository);
    }
}
