<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiSiteHostingPlanResource;
use Modules\AI\Repositories\AiSiteHostingPlanRepository;

/** Site hosting plans catalog: list (+status counts), create/update with translations, status, bulk delete, trash. */
class AiSiteHostingPlanService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiSiteHostingPlanResource::class;

    public function __construct(AiSiteHostingPlanRepository $repository)
    {
        parent::__construct($repository);
    }
}
