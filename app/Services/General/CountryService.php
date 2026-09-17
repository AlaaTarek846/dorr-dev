<?php

namespace App\Services\General;

use App\Services\CatalogService;
use App\Http\Resources\General\CountryResource;
use App\Repositories\General\CountryRepository;

class CountryService extends CatalogService
{
    protected ?string $resource = CountryResource::class;

    public function __construct(CountryRepository $repository)
    {
        parent::__construct($repository);
    }
}
