<?php

namespace App\Services;

use App\Http\Resources\LanguageResource;
use App\Repositories\LanguageRepository;

class LanguageService extends CatalogService
{
    protected ?string $resource = LanguageResource::class;

    public function __construct(LanguageRepository $repository)
    {
        parent::__construct($repository);
    }
}
