<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiFeatureFlagResource;
use Modules\AI\Repositories\AiFeatureFlagRepository;

class AiFeatureFlagService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiFeatureFlagResource::class;

    public function __construct(AiFeatureFlagRepository $repository)
    {
        parent::__construct($repository);
    }
}
