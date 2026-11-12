<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiProviderDataRuleResource;
use Modules\AI\Repositories\AiProviderDataRuleRepository;

/** AI provider data rules catalog, no translations: list (+status counts), create/update, status, bulk delete, trash. */
class AiProviderDataRuleService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiProviderDataRuleResource::class;

    public function __construct(AiProviderDataRuleRepository $repository)
    {
        parent::__construct($repository);
    }
}
