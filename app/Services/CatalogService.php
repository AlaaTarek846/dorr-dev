<?php

namespace App\Services;

use App\Repositories\BaseRepository;
use App\Services\Concerns\ManagesCatalog;

abstract class CatalogService extends BaseService
{
    use ManagesCatalog;

    public function __construct(BaseRepository $repository)
    {
        parent::__construct($repository);
    }
}
