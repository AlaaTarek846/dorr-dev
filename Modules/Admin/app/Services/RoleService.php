<?php

namespace Modules\Admin\Services;

use App\Services\BaseService;
use Modules\Admin\Http\Resources\RoleResource;
use Modules\Admin\Repositories\RoleRepository;

class RoleService extends BaseService
{
    protected ?string $resource = RoleResource::class;

    public function __construct(RoleRepository $repository)
    {
        parent::__construct($repository);
    }
}
