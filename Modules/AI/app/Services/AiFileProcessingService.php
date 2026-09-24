<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiFileProcessingResource;
use Modules\AI\Repositories\AiFileProcessingRepository;

class AiFileProcessingService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiFileProcessingResource::class;

    public function __construct(AiFileProcessingRepository $repository)
    {
        parent::__construct($repository);
    }
}
