<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiRoutingPolicyResource;
use Modules\AI\Repositories\AiRoutingPolicyRepository;

class AiRoutingPolicyService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiRoutingPolicyResource::class;

    public function __construct(AiRoutingPolicyRepository $repository)
    {
        parent::__construct($repository);
    }
}
