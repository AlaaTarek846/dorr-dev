<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiRequestResource;
use Modules\AI\Repositories\AiRequestRepository;

class AiRequestService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiRequestResource::class;

    public function __construct(AiRequestRepository $repository)
    {
        parent::__construct($repository);
    }
}
