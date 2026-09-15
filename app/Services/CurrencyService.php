<?php

namespace App\Services;

use App\Http\Resources\CurrencyResource;
use App\Repositories\CurrencyRepository;

class CurrencyService extends CatalogService
{
    protected ?string $resource = CurrencyResource::class;

    public function __construct(CurrencyRepository $repository)
    {
        parent::__construct($repository);
    }
}
