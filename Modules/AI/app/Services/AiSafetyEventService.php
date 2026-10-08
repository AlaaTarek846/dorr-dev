<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiSafetyEventResource;
use Modules\AI\Repositories\AiSafetyEventRepository;

class AiSafetyEventService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiSafetyEventResource::class;

    public function __construct(AiSafetyEventRepository $repository)
    {
        parent::__construct($repository);
    }
}
