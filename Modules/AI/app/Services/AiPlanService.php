<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiPlanResource;
use Modules\AI\Repositories\AiPlanRepository;

/** AI plans catalog: list (+status counts), create/update with translations, status, bulk delete, trash. */
class AiPlanService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiPlanResource::class;

    public function __construct(AiPlanRepository $repository)
    {
        parent::__construct($repository);
    }
}
