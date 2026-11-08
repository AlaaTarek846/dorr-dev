<?php

namespace Modules\Wallet\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Wallet\Http\Requests\WithdrawalMethodRequest;
use Modules\Wallet\Http\Resources\WithdrawalMethodResource;
use Modules\Wallet\Services\WithdrawalMethodService;

/**
 * The provider's saved payout destinations. Adding and editing sit behind
 * RequiresWalletPin (see routes/provider.php): redirecting where money is
 * paid out is exactly what a stolen session would try first.
 */
class WithdrawalMethodController extends Controller
{
    public function __construct(private readonly WithdrawalMethodService $methods) {}

    public function index(Request $request)
    {
        return ApiResponse::success(
            WithdrawalMethodResource::collection($this->methods->list($request->user())),
            __('api.retrieved'),
        );
    }

    public function store(WithdrawalMethodRequest $request)
    {
        return ApiResponse::created(
            new WithdrawalMethodResource($this->methods->create($request->user(), $request->methodAttributes())),
            __('api.created'),
        );
    }

    public function update(WithdrawalMethodRequest $request, int $method)
    {
        return ApiResponse::success(
            new WithdrawalMethodResource($this->methods->update($request->user(), $method, $request->methodAttributes())),
            __('api.updated'),
        );
    }

    public function favorite(Request $request, int $method)
    {
        $owner = $request->user();

        return ApiResponse::success(
            new WithdrawalMethodResource($this->methods->makeFavorite($owner, $this->methods->find($owner, $method))),
            __('api.updated'),
        );
    }

    public function destroy(Request $request, int $method)
    {
        $this->methods->delete($request->user(), $method);

        return ApiResponse::noContent(__('api.deleted'));
    }
}
