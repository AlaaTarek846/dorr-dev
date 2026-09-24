<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiSubscriptionResource;
use Modules\AI\Repositories\AiSubscriptionRepository;

class AiSubscriptionService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiSubscriptionResource::class;

    public function __construct(AiSubscriptionRepository $repository)
    {
        parent::__construct($repository);
    }
}
