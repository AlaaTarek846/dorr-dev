<?php

namespace App\Services\General;

use App\Http\Resources\General\PrivacyPolicyResource;
use App\Repositories\General\PrivacyPolicyRepository;
use App\Services\CatalogService;

class PrivacyPolicyService extends CatalogService
{
    protected ?string $resource = PrivacyPolicyResource::class;

    public function __construct(PrivacyPolicyRepository $repository)
    {
        parent::__construct($repository);
    }
}
