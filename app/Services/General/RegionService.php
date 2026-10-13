<?php

namespace App\Services\General;

use App\Http\Resources\General\RegionResource;
use App\Repositories\General\RegionRepository;
use App\Services\CatalogService;

class RegionService extends CatalogService
{
    protected ?string $resource = RegionResource::class;

    public function __construct(RegionRepository $repository)
    {
        parent::__construct($repository);
    }
}
