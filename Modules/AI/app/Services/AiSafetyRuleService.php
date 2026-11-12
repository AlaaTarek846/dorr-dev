<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiSafetyRuleResource;
use Modules\AI\Repositories\AiSafetyRuleRepository;

/** AI safety rules catalog: list (+status counts), create/update with translations, status, bulk delete, trash. */
class AiSafetyRuleService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiSafetyRuleResource::class;

    public function __construct(AiSafetyRuleRepository $repository)
    {
        parent::__construct($repository);
    }
}
