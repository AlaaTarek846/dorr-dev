<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiUsageResource;
use Modules\AI\Repositories\AiUsageRepository;

class AiUsageService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiUsageResource::class;

    public function __construct(AiUsageRepository $repository)
    {
        parent::__construct($repository);
    }
}
