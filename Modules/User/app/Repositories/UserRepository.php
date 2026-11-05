<?php

namespace Modules\User\Repositories;

use App\Enums\UserStatus;
use App\Repositories\BaseRepository;
use App\Support\AccountBadge;
use Illuminate\Database\Eloquent\Builder;
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

    /** The dashboard's list: `?verified=1` only verified accounts, `?verified=0` only the others (AV-029). */
    protected function applyIndexDefaults(Builder $query): Builder
    {
        $query = parent::applyIndexDefaults($query);
        $verified = request()->input('verified');
        if ($verified !== null && $verified !== '') {
            $ids = AccountBadge::userIds();
            filter_var($verified, FILTER_VALIDATE_BOOLEAN) ? $query->whereIn('id', $ids) : $query->whereNotIn('id', $ids);
        }

        return $query;
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
