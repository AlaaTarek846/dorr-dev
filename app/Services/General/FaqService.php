<?php

namespace App\Services\General;

use App\Http\Resources\General\FaqResource;
use App\Repositories\General\FaqRepository;
use App\Services\CatalogService;

class FaqService extends CatalogService
{
    protected ?string $resource = FaqResource::class;

    public function __construct(FaqRepository $repository)
    {
        parent::__construct($repository);
    }
}
