<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiDomainPolicyResource;
use Modules\AI\Repositories\AiDomainPolicyRepository;

class AiDomainPolicyService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiDomainPolicyResource::class;

    public function __construct(AiDomainPolicyRepository $repository)
    {
        parent::__construct($repository);
    }
}
