<?php

namespace Modules\Wallet\Services;

use Illuminate\Http\Request;
use Modules\Wallet\Enums\PaymentGatewayLogEvent;
use Modules\Wallet\Enums\PaymentTransactionStatus;
use Modules\Wallet\Enums\WebhookInboxStatus;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Models\WebhookInboxEntry;
use Modules\Wallet\Support\Payments\LogSanitizer;

/**
 * Handles the customer's browser landing back from the gateway.
 *
 * None of the three gateways we integrate (MyFatoorah, ARB, URPay) posts a
 * separate server-to-server webhook — the redirect *is* the notification — so
 * webhook_inbox here dedups/audits those inbound redirects (event_id = the
 * gateway's own payment id). What makes it safe is not the request itself but
 * that every driver re-verifies with the gateway before we believe it.
 */
class PaymentCallbackService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly PaymentCompletionService $completion,
        private readonly PaymentLogger $logger,
    ) {}

    /**
     * Never throws for a bad/unknown callback — the caller renders a page for
     * a browser, not an API error. Returns null when the reference is unknown.
     */
    public function handle(string $uuid, Request $request): ?PaymentTransaction
    {
        $payment = PaymentTransaction::query()->where('uuid', $uuid)->with('paymentMethod')->first();
        $externalId = $this->externalId($request);

        if ($payment === null) {
            $this->logger->record(null, 'unknown', PaymentGatewayLogEvent::RedirectVerify, 'inbound',
                request: $request->all(), gatewayStatus: 'unknown_reference', externalReference: $externalId ?? $uuid);

            return null;
        }

        $method = $payment->paymentMethod;

        // Already settled: a second visit (refresh, double redirect) is a no-op.
        if (! $payment->status->canBeCompleted()) {
            return $payment;
        }

        $entry = $this->inbox($payment, $externalId ?? $payment->uuid, $request);

        if ($entry->status === WebhookInboxStatus::Processed) {
            return $payment->fresh();
        }

        $entry->update(['status' => WebhookInboxStatus::Processing, 'attempts' => $entry->attempts + 1]);

        try {
            $result = $this->gateways->for($method)->handleCallback($request, ['credentials' => $method->credentials ?? []]);
        } catch (\Throwable $e) {
            // Gateway unreachable / undecryptable trandata — keep the payment
            // pending so a later reconcile can settle it.
            $entry->update(['status' => WebhookInboxStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            $this->logger->record($payment, $method->gateway, PaymentGatewayLogEvent::RedirectVerify, 'inbound',
                request: $request->all(), gatewayStatus: 'exception', externalReference: $externalId);

            return $payment;
        }

        $this->logger->record(
            $payment,
            $method->gateway,
            PaymentGatewayLogEvent::RedirectVerify,
            'inbound',
            request: $request->all(),
            response: $result->rawResponse,
            gatewayStatus: $result->confirmed ? 'confirmed' : ($result->errorCode ?? 'not_confirmed'),
            externalReference: $result->gatewayReference ?? $externalId,
        );

        // The gateway confirmed *some* payment — make sure it is THIS payment's.
        // Without this, someone could pay a small/cheap invoice and replay its
        // reference against another payment's callback URL (or credit one real
        // payment twice). initiate() stored the gateway's own id for this payment.
        if ($result->confirmed
            && $payment->gateway_reference !== null
            && $result->gatewayReference !== null
            && (string) $result->gatewayReference !== (string) $payment->gateway_reference) {
            $entry->update(['status' => WebhookInboxStatus::Failed, 'error' => 'reference_mismatch', 'processed_at' => now()]);
            $payment->update(['failure_reason' => 'reference_mismatch']);

            return $payment;
        }

        $entry->update(['valid_signature' => $result->rawResponse !== null || $result->confirmed]);

        $payment = $this->completion->complete($payment, $result);

        $entry->update([
            'status' => $payment->status === PaymentTransactionStatus::Pending ? WebhookInboxStatus::Failed : WebhookInboxStatus::Processed,
            'processed_at' => now(),
        ]);

        return $payment;
    }

    private function inbox(PaymentTransaction $payment, string $eventId, Request $request): WebhookInboxEntry
    {
        $payload = LogSanitizer::redact($request->all()) ?? [];

        return WebhookInboxEntry::query()->firstOrCreate(
            ['provider_code' => $payment->paymentMethod->gateway, 'event_id' => $eventId],
            [
                'payment_method_id' => $payment->payment_method_id,
                'payment_transaction_id' => $payment->id,
                'valid_signature' => false,
                'payload' => $payload,
                'payload_hash' => hash('sha256', json_encode($request->all())),
                'status' => WebhookInboxStatus::Received,
            ],
        );
    }

    /**
     * MyFatoorah sends `paymentId`, ARB sends `paymentid`.
     */
    private function externalId(Request $request): ?string
    {
        $id = $request->input('paymentId') ?? $request->input('paymentid');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
