<?php

namespace App\Services;

use App\Repositories\TranslatableRepository;
use App\Services\Concerns\ManagesCatalog;

abstract class CatalogService extends BaseService
{
    use ManagesCatalog;

    public function __construct(TranslatableRepository $repository)
    {
        parent::__construct($repository);
    }
}
