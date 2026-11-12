<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiRoutingPolicyResource;
use Modules\AI\Repositories\AiRoutingPolicyRepository;

/** AI routing policies catalog: list (+status counts), create/update with translations, status, bulk delete, trash, dropdown. */
class AiRoutingPolicyService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiRoutingPolicyResource::class;

    public function __construct(AiRoutingPolicyRepository $repository)
    {
        parent::__construct($repository);
    }
}
