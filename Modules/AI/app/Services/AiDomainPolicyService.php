<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiDomainPolicyResource;
use Modules\AI\Repositories\AiDomainPolicyRepository;

/** AI domain policies catalog: list (+status counts), create/update with translations, status, bulk delete, trash. */
class AiDomainPolicyService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiDomainPolicyResource::class;

    public function __construct(AiDomainPolicyRepository $repository)
    {
        parent::__construct($repository);
    }
}
