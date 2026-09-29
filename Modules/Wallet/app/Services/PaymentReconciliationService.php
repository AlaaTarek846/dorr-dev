<?php

namespace Modules\Wallet\Services;

use Modules\Wallet\Enums\PaymentGatewayLogEvent;
use Modules\Wallet\Exceptions\TopupException;
use Modules\Wallet\Exceptions\UnsupportedGatewayOperationException;
use Modules\Wallet\Models\PaymentTransaction;

/**
 * Admin "Reconcile" on a payment stuck in pending/expired — the browser never
 * made it back, so we ask the gateway directly. Unlike a customer callback a
 * "not confirmed" answer here never fails the payment: the bank may simply
 * still be processing, and an admin can check again later.
 */
class PaymentReconciliationService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly PaymentCompletionService $completion,
        private readonly PaymentLogger $logger,
    ) {}

    /**
     * @param  array{type: string, id: int|null}|null  $triggeredBy
     *
     * @throws TopupException  not reconcilable, or the gateway can't be asked
     */
    public function reconcile(PaymentTransaction $payment, ?array $triggeredBy = null): PaymentTransaction
    {
        if (! $payment->status->canBeCompleted()) {
            throw TopupException::paymentNotPending();
        }

        if ($payment->gateway_reference === null) {
            throw TopupException::reconcileUnsupported();
        }

        $method = $payment->paymentMethod;
        $gateway = $this->gateways->for($method);
        $context = ($payment->gateway_context ?? []) + [
            'credentials' => $method->credentials ?? [],
            'amount_minor' => $payment->requested_amount_minor,
            'track_id' => $payment->uuid,
        ];

        try {
            $result = $gateway->verify($payment->gateway_reference, $context);
        } catch (UnsupportedGatewayOperationException) {
            throw TopupException::reconcileUnsupported();
        }

        $payment->update([
            'reconciliation_attempts' => $payment->reconciliation_attempts + 1,
            'last_reconciled_at' => now(),
        ]);

        $this->logger->record(
            $payment,
            $method->gateway,
            PaymentGatewayLogEvent::ManualReconcile,
            'outbound',
            request: ['gateway_reference' => $payment->gateway_reference],
            response: $result->rawResponse,
            gatewayStatus: $result->confirmed ? 'confirmed' : ($result->errorCode ?? 'not_confirmed'),
            externalReference: $result->gatewayReference,
            triggeredBy: $triggeredBy,
        );

        // Not confirmed ⇒ leave it exactly as it is (see class docblock).
        return $result->confirmed ? $this->completion->complete($payment, $result) : $payment->fresh();
    }
}
