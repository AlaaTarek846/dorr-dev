<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiSecurityPolicyResource;
use Modules\AI\Repositories\AiSecurityPolicyRepository;

class AiSecurityPolicyService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiSecurityPolicyResource::class;

    public function __construct(AiSecurityPolicyRepository $repository)
    {
        parent::__construct($repository);
    }
}
