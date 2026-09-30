<?php

namespace App\Services\General;

use App\Http\Resources\General\LegalPageResource;
use App\Repositories\General\LegalPageRepository;
use App\Services\CatalogService;

class LegalPageService extends CatalogService
{
    protected ?string $resource = LegalPageResource::class;

    public function __construct(LegalPageRepository $repository)
    {
        parent::__construct($repository);
    }
}