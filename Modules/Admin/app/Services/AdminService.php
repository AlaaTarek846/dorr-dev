<?php

namespace Modules\Admin\Services;

use App\Services\BaseService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Admin\Http\Resources\AdminResource;
use Modules\Admin\Repositories\AdminRepository;

class AdminService extends BaseService
{
    protected ?string $resource = AdminResource::class;

    public function __construct(AdminRepository $repository)
    {
        parent::__construct($repository);
    }

    public function changeStatus(int|string $id, bool $status, ?string $message = null): JsonResponse
    {
        /** @var AdminRepository $repository */
        $repository = $this->repository;
        $admin = $repository->changeStatus($id, $status);

        return ApiResponse::success(
            $this->transformResource($admin),
            $message ?? __('api.updated'),
        );
    }

    protected function beforeStore(array $data): array
    {
        return $this->mapAvatarMedia($data);
    }

    protected function beforeUpdate(int|string $id, array $data): array
    {
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
