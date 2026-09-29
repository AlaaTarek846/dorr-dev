<?php

namespace Modules\Wallet\Http\Controllers\General;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Wallet\Enums\PaymentTransactionStatus;
use Modules\Wallet\Services\PaymentCallbackService;

/**
 * Where the gateway sends the customer's browser back. Public on purpose (no
 * auth: it's a browser redirect) and safe because the payment is identified by
 * an unguessable uuid and *nothing in the request is trusted* — the driver
 * re-verifies with the gateway before any money moves. Answers with a small
 * HTML page (the customer is in a browser/webview, not calling an API).
 */
class PaymentCallbackController extends Controller
{
    public function __construct(private readonly PaymentCallbackService $callbacks) {}

    public function __invoke(Request $request, string $uuid)
    {
        $payment = $this->callbacks->handle($uuid, $request);

        $state = match ($payment?->status) {
            PaymentTransactionStatus::Paid => 'success',
            PaymentTransactionStatus::Refunded => 'processed',
            PaymentTransactionStatus::Pending => 'pending',
            default => 'failed',
        };

        return response()->view('wallet::payment-result', ['state' => $state]);
    }
}
