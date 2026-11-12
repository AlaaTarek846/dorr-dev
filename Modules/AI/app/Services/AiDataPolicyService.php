<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiDataPolicyResource;
use Modules\AI\Repositories\AiDataPolicyRepository;

/** AI data policies catalog: list (+status counts), create/update with translations, status, bulk delete, trash. */
class AiDataPolicyService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiDataPolicyResource::class;

    public function __construct(AiDataPolicyRepository $repository)
    {
        parent::__construct($repository);
    }
}
