<?php

namespace App\Services;

use App\Http\Resources\FlagResource;
use App\Repositories\FlagRepository;

class FlagService extends CatalogService
{
    protected ?string $resource = FlagResource::class;

    public function __construct(FlagRepository $repository)
    {
        parent::__construct($repository);
    }
}
