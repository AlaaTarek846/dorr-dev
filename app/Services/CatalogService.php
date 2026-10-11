<?php

namespace App\Services;

use App\Repositories\BaseRepository;
use App\Services\Concerns\ManagesCatalog;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

abstract class CatalogService extends BaseService
{
    use ManagesCatalog;

    public function __construct(BaseRepository $repository)
    {
        parent::__construct($repository);
    }

    /**
     * The list, and — when asked (`?status_counts=1`) — how many records are active / inactive / in all, as `status_counts` next to `data`.
     * The counts are of the whole list (not of the current search), so the page's All / Active / Inactive tabs need no
     * requests of their own: the one list request carries them.
     */
    public function list(?string $message = null, ?string $groupBy = null): JsonResponse
    {
        $result = $this->allOrPaginate(groupBy: $groupBy);
        $meta = request()->boolean('status_counts') ? ['status_counts' => $this->statusCounts()] : null;

        return ApiResponse::success($result['data'], $message ?? __('api.retrieved'), 200, $result['pagination'], $meta);
    }

    /**
     * @return array{total: int, active: int, inactive: int}
     */
    protected function statusCounts(): array
    {
        $total = $this->repository->query()->count();
        $active = $this->repository->query()->where('status', true)->count();

        return ['total' => $total, 'active' => $active, 'inactive' => $total - $active];
    }
}
