<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiSafetyPolicyResource;
use Modules\AI\Repositories\AiSafetyPolicyRepository;

class AiSafetyPolicyService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiSafetyPolicyResource::class;

    public function __construct(AiSafetyPolicyRepository $repository)
    {
        parent::__construct($repository);
    }
}
