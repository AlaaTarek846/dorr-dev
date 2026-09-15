<?php

namespace Modules\Admin\Repositories;

use App\Repositories\BaseRepository;
use Illuminate\Support\Facades\DB;
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

    /**
     * @param  list<int|string>  $ids
     */
    public function deleteMultiple(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            $deleted = 0;

            foreach ($ids as $id) {
                if ($this->destroy($id)) {
                    $deleted++;
                }
            }

            return $deleted;
        });
    }

    public function changeStatus(int|string $id, bool $status): Admin
    {
        $admin = $this->query()->findOrFail($id);
        $admin->update(['status' => $status]);

        return $this->refresh($admin);
    }
}
