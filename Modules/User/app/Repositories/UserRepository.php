<?php

namespace Modules\User\Repositories;

use App\Enums\UserStatus;
use App\Repositories\BaseRepository;
use Illuminate\Support\Facades\DB;
use Modules\User\Models\User;

class UserRepository extends BaseRepository
{
    protected array $with = [
        'country.flag',
    ];

    protected array $orderBy = [
        'id' => 'desc',
    ];

    public function __construct(User $model)
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

    public function changeStatus(int|string $id, UserStatus $status): User
    {
        $user = $this->query()->findOrFail($id);
        $user->update(['status' => $status]);

        return $this->refresh($user);
    }
}
