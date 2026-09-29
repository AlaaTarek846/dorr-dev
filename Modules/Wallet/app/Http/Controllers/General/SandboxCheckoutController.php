<?php

namespace Modules\Wallet\Http\Controllers\General;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Wallet\Enums\PaymentTransactionStatus;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Services\Gateways\SandboxGateway;

/**
 * The fake bank's checkout page (development/demo only — 404 unless
 * `wallet.sandbox_enabled`). "Approve" flips the sandbox state to paid and
 * sends the browser back to the wallet callback with just the reference,
 * exactly like a real gateway redirect; the wallet then verifies with
 * SandboxGateway::verify() before crediting anything.
 */
class SandboxCheckoutController extends Controller
{
    public function show(string $reference)
    {
        abort_unless(config('wallet.sandbox_enabled'), 404);

        $state = $this->state($reference);

        if ($state === null) {
            return $this->missing($reference);
        }

        return response()->view('wallet::sandbox-checkout', [
            'reference' => $reference,
            'missing' => false,
            'amount' => number_format($state['amount_minor'] / 100, 2),
            'currency' => $state['currency'],
            'settled' => $state['status'] !== 'pending',
        ]);
    }

    public function decide(Request $request, string $reference)
    {
        abort_unless(config('wallet.sandbox_enabled'), 404);

        $state = $this->state($reference);

        if ($state === null) {
            return $this->missing($reference);
        }

        $result = $request->validate(['result' => ['required', 'in:approve,decline']])['result'];

        if ($state['status'] === 'pending') {
            $state['status'] = $result === 'approve' ? 'paid' : 'failed';
            Cache::put(SandboxGateway::stateKey($reference), $state, now()->addMinutes(60));
        }

        return redirect()->away($state['return_url'].'?'.http_build_query(['reference' => $reference]));
    }

    /**
     * An unknown / long-gone payment gets a page a person can read, not a raw JSON 404 inside an iframe.
     */
    private function missing(string $reference)
    {
        return response()->view('wallet::sandbox-checkout', [
            'reference' => $reference,
            'missing' => true,
            'amount' => '',
            'currency' => '',
            'settled' => false,
        ], 404);
    }

    /**
     * The fake bank's memory of a payment. It lives in the cache (like a real bank's session), but
     * the wallet's own payment row is enough to rebuild a *pending* one — so clearing the cache, or
     * re-opening an old "Open payment page" link, doesn't strand a payment that is still waiting.
     *
     * @return array<string, mixed>|null
     */
    private function state(string $reference): ?array
    {
        $cached = Cache::get(SandboxGateway::stateKey($reference));

        if (is_array($cached)) {
            return $cached;
        }

        $payment = PaymentTransaction::query()
            ->where('gateway_reference', $reference)
            ->where('status', PaymentTransactionStatus::Pending)
            ->whereHas('paymentMethod', fn ($query) => $query->where('gateway', 'sandbox'))
            ->with('currency:id,code')
            ->first();

        if ($payment === null) {
            return null;
        }

        $state = [
            'status' => 'pending',
            'amount_minor' => $payment->requested_amount_minor,
            'currency' => $payment->currency?->code,
            'local_reference' => $payment->uuid,
            'return_url' => route('api.wallet.payments.callback', ['uuid' => $payment->uuid]),
        ];

        Cache::put(SandboxGateway::stateKey($reference), $state, now()->addMinutes(60));

        return $state;
    }
}
