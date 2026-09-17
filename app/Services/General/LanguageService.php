<?php

namespace App\Services\General;

use App\Services\CatalogService;
use App\Http\Resources\General\LanguageResource;
use App\Repositories\General\LanguageRepository;

class LanguageService extends CatalogService
{
    protected ?string $resource = LanguageResource::class;

    public function __construct(LanguageRepository $repository)
    {
        parent::__construct($repository);
    }
}
