<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiSecurityPolicyResource;
use Modules\AI\Repositories\AiSecurityPolicyRepository;

/** AI security policies catalog: list (+status counts), create/update with translations, status, bulk delete, trash. */
class AiSecurityPolicyService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiSecurityPolicyResource::class;

    public function __construct(AiSecurityPolicyRepository $repository)
    {
        parent::__construct($repository);
    }
}
