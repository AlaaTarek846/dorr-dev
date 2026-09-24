<?php

namespace Modules\Wallet\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Modules\Wallet\Exceptions\WithdrawalException;
use Modules\Wallet\Http\Requests\WithdrawalRequestFormRequest;
use Modules\Wallet\Http\Resources\WithdrawalRequestResource;
use Modules\Wallet\Models\WithdrawalRequest;
use Modules\Wallet\Services\WithdrawalService;

/**
 * Admin review of withdrawal requests: see who wants what and where to pay it,
 * then approve (receipt mandatory) or reject (reason mandatory).
 */
class WithdrawalRequestController extends Controller implements HasMiddleware
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    /**
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('withdrawal-requests', [
            ['view', ['index', 'show', 'receipt']],
            ['approve', ['approve']],
            ['reject', ['reject']],
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'country_id' => ['nullable', 'integer'],
            'owner_id' => ['nullable', 'integer'],
        ]);

        $query = WithdrawalRequest::query()
            ->with(['method', 'wallet.currency', 'wallet.country'])
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['country_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('wallet', fn (Builder $w) => $w->where('country_id', $v)))
            ->when($filters['owner_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('wallet', fn (Builder $w) => $w->where('owner_id', $v)))
            // Oldest pending first is what a reviewer wants; decided ones by recency.
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('id');

        return ApiResponse::paginated($query, WithdrawalRequestResource::class, __('api.retrieved'));
    }

    public function show(int $withdrawal_request)
    {
        return ApiResponse::success(new WithdrawalRequestResource($this->find($withdrawal_request)), __('api.retrieved'));
    }

    public function approve(WithdrawalRequestFormRequest $request, int $withdrawal_request)
    {
        $this->withdrawals->approve(
            $this->find($withdrawal_request),
            $request->file('receipt'),
            $request->validated('note'),
            (int) auth('admin_api')->id(),
        );

        return ApiResponse::success(new WithdrawalRequestResource($this->find($withdrawal_request)), __('api.updated'));
    }

    public function reject(WithdrawalRequestFormRequest $request, int $withdrawal_request)
    {
        $this->withdrawals->reject(
            $this->find($withdrawal_request),
            $request->validated('rejection_reason'),
            (int) auth('admin_api')->id(),
        );

        return ApiResponse::success(new WithdrawalRequestResource($this->find($withdrawal_request)), __('api.updated'));
    }

    public function receipt(int $withdrawal_request)
    {
        $media = $this->find($withdrawal_request)->getFirstMedia('receipt') ?? throw WithdrawalException::noReceipt();

        return response()->download($media->getPath(), $media->file_name);
    }

    private function find(int $id): WithdrawalRequest
    {
        return WithdrawalRequest::query()->with(['method', 'wallet.currency', 'wallet.country'])->findOrFail($id);
    }
}
