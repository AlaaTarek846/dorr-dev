<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiRoutingRuleResource;
use Modules\AI\Repositories\AiRoutingRuleRepository;

/** AI routing rules catalog (no translations): list (+status counts), create/update, status, bulk delete, trash. */
class AiRoutingRuleService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiRoutingRuleResource::class;

    public function __construct(AiRoutingRuleRepository $repository)
    {
        parent::__construct($repository);
    }
}
