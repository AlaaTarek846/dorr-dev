<?php

namespace Modules\Wallet\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Modules\Wallet\Http\Resources\PinRecoveryRequestResource;
use Modules\Wallet\Models\PinRecoveryRequest;
use Modules\Wallet\Services\WalletRecoveryService;

/**
 * Review of "I forgot my wallet PIN" requests made with an ID / passport photo: the reviewer sees the
 * photo from PIN setup next to the new one, then approves (the PIN becomes 0000 and the owner is told)
 * or rejects with a reason.
 */
class PinRecoveryRequestController extends Controller implements HasMiddleware
{
    public function __construct(private readonly WalletRecoveryService $recovery) {}

    /**
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('pin-recovery-requests', [
            ['view', ['index', 'show', 'image']],
            ['approve', ['approve']],
            ['reject', ['reject']],
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']]);

        $query = PinRecoveryRequest::query()
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            // Pending first (that is the work), then by recency.
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('id');

        return ApiResponse::paginated($query, PinRecoveryRequestResource::class, __('api.retrieved'));
    }

    public function show(int $pin_recovery_request)
    {
        return ApiResponse::success(new PinRecoveryRequestResource(PinRecoveryRequest::query()->findOrFail($pin_recovery_request)), __('api.retrieved'));
    }

    /** The two photos, streamed from the private disk to an authenticated admin only. */
    public function image(int $pin_recovery_request, string $kind)
    {
        abort_unless(in_array($kind, ['original', 'new'], true), 404);

        $media = PinRecoveryRequest::query()->findOrFail($pin_recovery_request)->getFirstMedia($kind.'_document');

        abort_if($media === null, 404);

        return response()->file($media->getPath(), ['Cache-Control' => 'private, no-store']);
    }

    public function approve(int $pin_recovery_request)
    {
        $approved = $this->recovery->approve(PinRecoveryRequest::query()->findOrFail($pin_recovery_request), (int) auth('admin_api')->id());

        return ApiResponse::success(new PinRecoveryRequestResource($approved), __('api.updated'));
    }

    public function reject(Request $request, int $pin_recovery_request)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'min:3', 'max:500']]);

        $rejected = $this->recovery->reject(PinRecoveryRequest::query()->findOrFail($pin_recovery_request), $data['rejection_reason'], (int) auth('admin_api')->id());

        return ApiResponse::success(new PinRecoveryRequestResource($rejected), __('api.updated'));
    }
}
