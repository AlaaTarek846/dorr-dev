<?php

namespace Modules\Admin\Repositories;

use App\Repositories\BaseRepository;
use Modules\Admin\Models\Admin;

class AdminRepository extends BaseRepository
{
    protected array $with = ['country'];

    protected array $orderBy = [
        'id' => 'desc',
    ];

    public function __construct(Admin $model)
    {
        $this->model = $model;
    }

    public function changeStatus(int|string $id, bool $status): Admin
    {
        $admin = $this->query()->findOrFail($id);
        $admin->update(['status' => $status]);

        return $this->refresh($admin);
    }
}
