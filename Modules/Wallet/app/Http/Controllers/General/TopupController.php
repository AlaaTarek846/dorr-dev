<?php

namespace Modules\Wallet\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Wallet\Exceptions\TopupException;
use Modules\Wallet\Http\Requests\TopupRequest;
use Modules\Wallet\Http\Resources\PaymentTransactionResource;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Services\PaymentTopupService;
use Modules\Wallet\Support\OwnerType;

/**
 * Online top-up, mounted under both mobile/user and provider groups. Every
 * endpoint acts only on the authenticated owner's own payments and the
 * country resolved by the `country` middleware.
 *
 * `store` and `confirm` are behind RequiresWalletPin (see the route files);
 * `quote` is read-only so it isn't.
 */
class TopupController extends Controller
{
    public function __construct(private readonly PaymentTopupService $topups) {}

    public function quote(TopupRequest $request)
    {
        $quote = $this->topups->quote(
            $request->user(),
            $this->country(),
            PaymentMethod::query()->findOrFail($request->validated('payment_method_id')),
            (int) $request->validated('amount_minor'),
        );

        return ApiResponse::success($quote->toArray(), __('api.retrieved'));
    }

    public function store(TopupRequest $request)
    {
        $payment = $this->topups->initiate(
            $request->user(),
            $this->country(),
            PaymentMethod::query()->findOrFail($request->validated('payment_method_id')),
            (int) $request->validated('amount_minor'),
            $request->validated('idempotency_key'),
            app()->getLocale(),
        );

        return ApiResponse::created(new PaymentTransactionResource($payment->load('paymentMethod')), __('api.created'));
    }

    public function show(Request $request, string $uuid)
    {
        return ApiResponse::success(new PaymentTransactionResource($this->ownPayment($request, $uuid)), __('api.retrieved'));
    }

    public function confirm(TopupRequest $request, string $uuid)
    {
        $payment = $this->topups->confirmOtp($this->ownPayment($request, $uuid), $request->validated('otp'), app()->getLocale());

        return ApiResponse::success(new PaymentTransactionResource($payment->load('paymentMethod')), __('api.updated'));
    }

    private function country(): Country
    {
        $country = currentCountry();

        abort_if($country === null, 500, 'The country middleware did not run on this route.');

        return $country;
    }

    private function ownPayment(Request $request, string $uuid): PaymentTransaction
    {
        $owner = $request->user();

        return PaymentTransaction::query()
            ->with('paymentMethod')
            ->where('uuid', $uuid)
            ->where('owner_type', OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey())
            ->first() ?? throw TopupException::paymentNotFound();
    }
}
