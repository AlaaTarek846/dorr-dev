<?php

namespace App\Services\General;

use App\Http\Resources\General\FlagResource;
use App\Repositories\General\FlagRepository;
use App\Services\CatalogService;

class FlagService extends CatalogService
{
    protected ?string $resource = FlagResource::class;

    public function __construct(FlagRepository $repository)
    {
        parent::__construct($repository);
    }
}
