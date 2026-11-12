<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiSafetyPolicyResource;
use Modules\AI\Repositories\AiSafetyPolicyRepository;

/** AI safety policies catalog: list (+status counts), create/update with translations, status, bulk delete, trash, dropdown. */
class AiSafetyPolicyService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiSafetyPolicyResource::class;

    public function __construct(AiSafetyPolicyRepository $repository)
    {
        parent::__construct($repository);
    }
}
