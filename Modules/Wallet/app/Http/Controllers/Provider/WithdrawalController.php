<?php

namespace Modules\Wallet\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Wallet\Exceptions\WithdrawalException;
use Modules\Wallet\Http\Requests\WithdrawalRequestFormRequest;
use Modules\Wallet\Http\Resources\WithdrawalRequestResource;
use Modules\Wallet\Models\WithdrawalRequest;
use Modules\Wallet\Services\WithdrawalMethodService;
use Modules\Wallet\Services\WithdrawalService;
use Modules\Wallet\Support\OwnerType;

/**
 * The provider's own withdrawal requests. `store` is behind RequiresWalletPin.
 * Everything is scoped through the owner's wallets — a request id that
 * belongs to someone else is simply "not found".
 */
class WithdrawalController extends Controller
{
    public function __construct(
        private readonly WithdrawalService $withdrawals,
        private readonly WithdrawalMethodService $methods,
    ) {}

    public function index(Request $request)
    {
        return ApiResponse::paginated(
            $this->ownRequests($request)->with(['method', 'wallet.currency'])->latest('id'),
            WithdrawalRequestResource::class,
            __('api.retrieved'),
        );
    }

    public function show(Request $request, int $withdrawal)
    {
        return ApiResponse::success(
            new WithdrawalRequestResource($this->findOwn($request, $withdrawal)),
            __('api.retrieved'),
        );
    }

    public function store(WithdrawalRequestFormRequest $request)
    {
        $owner = $request->user();

        $withdrawal = $this->withdrawals->request(
            $owner,
            $this->country(),
            $this->methods->find($owner, (int) $request->validated('withdrawal_method_id')),
            (int) $request->validated('amount_minor'),
            $request->validated('idempotency_key'),
        );

        return ApiResponse::created(new WithdrawalRequestResource($withdrawal->load(['method', 'wallet.currency'])), __('api.created'));
    }

    /**
     * The transfer receipt, once approved — from a private disk, so only the
     * owner of the request (checked above) can ever fetch it.
     */
    public function receipt(Request $request, int $withdrawal)
    {
        $media = $this->findOwn($request, $withdrawal)->getFirstMedia('receipt') ?? throw WithdrawalException::noReceipt();

        return response()->download($media->getPath(), $media->file_name);
    }

    private function country(): Country
    {
        $country = currentCountry();

        abort_if($country === null, 500, 'The country middleware did not run on this route.');

        return $country;
    }

    private function ownRequests(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $owner = $request->user();

        return WithdrawalRequest::query()->whereHas('wallet', fn ($q) => $q
            ->where('owner_type', OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey()));
    }

    private function findOwn(Request $request, int $id): WithdrawalRequest
    {
        return $this->ownRequests($request)->with(['method', 'wallet.currency'])->find($id) ?? throw WithdrawalException::notFound();
    }
}
