<?php

namespace Modules\Admin\Services;

use App\Services\BaseService;
use Modules\Admin\Http\Resources\PermissionResource;
use Modules\Admin\Repositories\PermissionRepository;

class PermissionService extends BaseService
{
    protected ?string $resource = PermissionResource::class;

    public function __construct(PermissionRepository $repository)
    {
        parent::__construct($repository);
    }
}
