<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiUsageSessionResource;
use Modules\AI\Repositories\AiUsageSessionRepository;

class AiUsageSessionService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiUsageSessionResource::class;

    public function __construct(AiUsageSessionRepository $repository)
    {
        parent::__construct($repository);
    }
}
