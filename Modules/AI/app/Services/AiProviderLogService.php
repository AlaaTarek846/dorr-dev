<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiProviderLogResource;
use Modules\AI\Repositories\AiProviderLogRepository;

class AiProviderLogService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiProviderLogResource::class;

    public function __construct(AiProviderLogRepository $repository)
    {
        parent::__construct($repository);
    }
}
