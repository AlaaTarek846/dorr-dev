<?php

namespace Modules\Provider\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Provider\Http\Requests\App\BranchRequest;
use Modules\Provider\Http\Requests\App\EstablishmentRequest;
use Modules\Provider\Http\Resources\App\BranchResource;
use Modules\Provider\Http\Resources\App\EstablishmentResource;
use Modules\Provider\Http\Resources\App\MembershipResource;
use Modules\Provider\Models\Branch;
use Modules\Provider\Services\Establishments\EstablishmentAccess;
use Modules\Provider\Services\Establishments\EstablishmentService;

/** The establishment the app is working in (A-03) and its branches (A-04). */
class EstablishmentController extends Controller
{
    public function __construct(
        private readonly EstablishmentService $establishments,
        private readonly EstablishmentAccess $access,
    ) {}

    public function store(EstablishmentRequest $request): JsonResponse
    {
        $membership = $this->establishments->create($request->user('provider_api'), $request->validated());

        return ApiResponse::created(new MembershipResource($membership->load('establishment')), __('provider.establishment_created'));
    }

    public function show(Request $request): JsonResponse
    {
        $membership = $this->access->current($request);

        return ApiResponse::success([
            'establishment' => new EstablishmentResource($membership->establishment->load(['services', 'branches' => fn ($q) => $q->orderByDesc('is_main')])),
            'membership' => new MembershipResource($membership),
        ], __('api.retrieved'));
    }

    public function update(EstablishmentRequest $request): JsonResponse
    {
        $establishment = $this->establishments->update($this->access->current($request), $request->validated());

        return ApiResponse::success(new EstablishmentResource($establishment->load('services')), __('api.updated'));
    }

    public function submit(Request $request): JsonResponse
    {
        $establishment = $this->establishments->submit($this->access->current($request));

        return ApiResponse::success(new EstablishmentResource($establishment), __('provider.establishment_submitted'));
    }

    public function branches(Request $request): JsonResponse
    {
        $membership = $this->access->current($request);
        $ids = $this->access->branchIds($membership);
        $branches = $membership->establishment->branches()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderByDesc('is_main')->orderBy('name')->get();

        return ApiResponse::success(BranchResource::collection($branches), __('api.retrieved'));
    }

    public function storeBranch(BranchRequest $request): JsonResponse
    {
        $branch = $this->establishments->saveBranch($this->access->current($request), $request->validated());

        return ApiResponse::created(new BranchResource($branch), __('api.created'));
    }

    public function updateBranch(BranchRequest $request, Branch $branch): JsonResponse
    {
        $branch = $this->establishments->saveBranch($this->access->current($request), $request->validated(), $branch);

        return ApiResponse::success(new BranchResource($branch), __('api.updated'));
    }

    public function destroyBranch(Request $request, Branch $branch): JsonResponse
    {
        $this->establishments->deleteBranch($this->access->current($request), $branch);

        return ApiResponse::noContent(__('api.deleted'));
    }
}
