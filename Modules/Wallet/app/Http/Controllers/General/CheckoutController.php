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

    private function country(): Country
    {
        $country = currentCountry();

        abort_if($country === null, 500, 'The country middleware did not run on this route.');

        return $country;
    }
}
