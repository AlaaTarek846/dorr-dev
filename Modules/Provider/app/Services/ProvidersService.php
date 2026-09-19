<?php

namespace Modules\Provider\Services;

use App\Services\BaseService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use App\Enums\UserStatus;
use Modules\Provider\Http\Resources\ProviderResource;
use Modules\Provider\Repositories\ProviderRepository;

class ProvidersService extends BaseService
{
    protected ?string $resource = ProviderResource::class;

    public function __construct(ProviderRepository $repository)
    {
        parent::__construct($repository);
    }

    public function changeStatus(int|string $id, UserStatus $status, ?string $message = null): JsonResponse
    {
        /** @var ProviderRepository $repository */
        $repository = $this->repository;
        $provider = $repository->changeStatus($id, $status);

        return ApiResponse::success(
            $this->transformResource($provider),
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
