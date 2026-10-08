<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiProviderHealthResource;
use Modules\AI\Repositories\AiProviderHealthRepository;

class AiProviderHealthService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiProviderHealthResource::class;

    public function __construct(AiProviderHealthRepository $repository)
    {
        parent::__construct($repository);
    }
}
