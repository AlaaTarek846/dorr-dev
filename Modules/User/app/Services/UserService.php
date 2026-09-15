<?php

namespace Modules\User\Services;

use App\Enums\UserStatus;
use App\Services\BaseService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\User\Http\Resources\UserResource;
use Modules\User\Repositories\UserRepository;

class UserService extends BaseService
{
    protected ?string $resource = UserResource::class;

    public function __construct(UserRepository $repository)
    {
        parent::__construct($repository);
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function deleteMultiple(array $ids, ?string $message = null): JsonResponse
    {
        /** @var UserRepository $repository */
        $repository = $this->repository;
        $deleted = $repository->deleteMultiple($ids);

        return ApiResponse::success(
            ['deleted' => $deleted],
            $message ?? __('api.deleted'),
        );
    }

    public function changeStatus(int|string $id, UserStatus $status, ?string $message = null): JsonResponse
    {
        /** @var UserRepository $repository */
        $repository = $this->repository;
        $user = $repository->changeStatus($id, $status);

        return ApiResponse::success(
            $this->transformResource($user),
            $message ?? __('api.updated'),
        );
    }

    protected function beforeStore(array $data): array
    {
        if (! isset($data['status'])) {
            $data['status'] = UserStatus::Active->value;
        }

        return $this->mapAvatarMedia($data);
    }

    protected function beforeUpdate(int|string $id, array $data): array
    {
        if (array_key_exists('password', $data) && blank($data['password'])) {
            unset($data['password']);
        }

        return $this->mapAvatarMedia($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mapAvatarMedia(array $data): array
    {
        if (! array_key_exists('avatar', $data)) {
            return $data;
        }

        if ($data['avatar'] !== null) {
            $data['media']['avatar'] = $data['avatar'];
        }

        unset($data['avatar']);

        return $data;
    }
}
