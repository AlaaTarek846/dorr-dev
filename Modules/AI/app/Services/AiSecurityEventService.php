<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiSecurityEventResource;
use Modules\AI\Repositories\AiSecurityEventRepository;

class AiSecurityEventService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiSecurityEventResource::class;

    public function __construct(AiSecurityEventRepository $repository)
    {
        parent::__construct($repository);
    }
}
