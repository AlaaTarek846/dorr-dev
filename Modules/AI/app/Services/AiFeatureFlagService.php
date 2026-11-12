<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiFeatureFlagResource;
use Modules\AI\Repositories\AiFeatureFlagRepository;

/** AI feature flags catalog: list (+status counts), create/update with translations, status, bulk delete, trash. */
class AiFeatureFlagService extends CatalogService
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
