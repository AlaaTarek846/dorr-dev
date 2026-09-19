<?php

namespace Modules\User\Repositories;

use App\Enums\UserStatus;
use App\Repositories\BaseRepository;
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

    public function changeStatus(int|string $id, bool $status): User
    {
        return $this->updateUserStatus(
            $id,
            $status ? UserStatus::Active : UserStatus::Inactive,
        );
    }

    public function updateUserStatus(int|string $id, UserStatus $status): User
    {
        $user = $this->query()->findOrFail($id);
        $user->update(['status' => $status]);

        return $this->refresh($user);
    }
}
