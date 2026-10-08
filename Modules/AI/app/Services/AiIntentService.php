<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiIntentResource;
use Modules\AI\Repositories\AiIntentRepository;

class AiIntentService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiIntentResource::class;

    public function __construct(AiIntentRepository $repository)
    {
        parent::__construct($repository);
    }
}
