<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiDataPolicyResource;
use Modules\AI\Repositories\AiDataPolicyRepository;

class AiDataPolicyService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiDataPolicyResource::class;

    public function __construct(AiDataPolicyRepository $repository)
    {
        parent::__construct($repository);
    }
}
