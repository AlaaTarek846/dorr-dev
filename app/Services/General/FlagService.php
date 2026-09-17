<?php

namespace App\Services\General;

use App\Services\CatalogService;
use App\Http\Resources\General\FlagResource;
use App\Repositories\General\FlagRepository;

class FlagService extends CatalogService
{
    protected ?string $resource = FlagResource::class;

    public function __construct(FlagRepository $repository)
    {
        parent::__construct($repository);
    }
}
