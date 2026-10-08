<?php

namespace Modules\Wallet\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Services\CheckoutService;

/**
 * The one payment screen, for every paid thing in the app (docs/remaining_chat.md ج.0). Mounted
 * with the other wallet routes (user + provider); `pay` is behind RequiresWalletPin.
 */
class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkouts) {}

    /** POST wallet/checkouts — `{purpose, reference}`; the server prices it. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', 'max:60'],
            'reference' => ['nullable', 'array'],
        ]);

        $owner = $request->user();
        $checkout = $this->checkouts->create($owner, $this->country(), $data['purpose'], $data['reference'] ?? []);

        return ApiResponse::created($this->checkouts->present($owner, $checkout), __('api.created'));
    }

    /** GET wallet/checkouts/{uuid} — the app polls it while a gateway payment settles. */
    public function show(Request $request, string $uuid)
    {
        $owner = $request->user();

        return ApiResponse::success($this->checkouts->present($owner, $this->checkouts->findOwn($owner, $uuid)), __('api.retrieved'));
    }

    /**
     * POST wallet/checkouts/{uuid}/pay — `{method: wallet}` or `{method: gateway, payment_method_id}`
     * (+ Idempotency-Key for a gateway). PIN in X-Wallet-Pin.
     */
    public function pay(Request $request, string $uuid)
    {
        if ($request->header('Idempotency-Key') !== null) {
            $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        }

        $data = $request->validate([
            'method' => ['required', Rule::in(['wallet', 'gateway'])],
            'payment_method_id' => ['required_if:method,gateway', 'nullable', 'integer', 'exists:payment_methods,id'],
            'idempotency_key' => ['required_if:method,gateway', 'nullable', 'string', 'min:8', 'max:100'],
        ]);

        $owner = $request->user();
        $checkout = $this->checkouts->findOwn($owner, $uuid);

        $checkout = $data['method'] === 'wallet'
            ? $this->checkouts->payWithWallet($owner, $checkout)
            : $this->checkouts->payWithGateway(
                $owner,
                $checkout,
                PaymentMethod::query()->findOrFail($data['payment_method_id']),
                $data['idempotency_key'],
                app()->getLocale(),
            );

        return ApiResponse::success($this->checkouts->present($owner, $checkout), __('api.updated'));
    }

    /** POST wallet/checkouts/{uuid}/coupon {code} — the discount shows on the payment screen. */
    public function coupon(Request $request, string $uuid, \Modules\Wallet\Services\CouponService $coupons)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $owner = $request->user();
        $checkout = $coupons->apply($owner, $this->checkouts->findOwn($owner, $uuid), $data['code']);

        return ApiResponse::success($this->checkouts->present($owner, $checkout), __('api.updated'));
    }

    public function removeCoupon(Request $request, string $uuid, \Modules\Wallet\Services\CouponService $coupons)
    {
        $owner = $request->user();
        $checkout = $coupons->remove($this->checkouts->findOwn($owner, $uuid));

        return ApiResponse::success($this->checkouts->present($owner, $checkout), __('api.updated'));
    }

    /** GET wallet/coupons — my coupons that can still be used. */
    public function coupons(Request $request, \Modules\Wallet\Services\CouponService $coupons)
    {
        return ApiResponse::success($coupons->mine($request->user()), __('api.retrieved'));
    }

    private function country(): Country
    {
        $country = currentCountry();

        abort_if($country === null, 500, 'The country middleware did not run on this route.');

        return $country;
    }
}
