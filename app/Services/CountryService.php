<?php

namespace App\Services;

use App\Http\Resources\CountryResource;
use App\Repositories\CountryRepository;

class CountryService extends CatalogService
{
    protected ?string $resource = CountryResource::class;

    public function __construct(CountryRepository $repository)
    {
        parent::__construct($repository);
    }
}
