<?php

namespace Modules\Wallet\Services;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Wallet\Enums\PaymentGatewayLogEvent;
use Modules\Wallet\Enums\PaymentMethodType;
use Modules\Wallet\Enums\PaymentTransactionStatus;
use Modules\Wallet\Exceptions\IdempotencyConflictException;
use Modules\Wallet\Exceptions\PaymentGatewayException;
use Modules\Wallet\Exceptions\TopupException;
use Modules\Wallet\Models\PaymentMethod;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Support\OwnerType;
use Modules\Wallet\Support\Payments\GatewayChargeRequest;
use Modules\Wallet\Support\Payments\LogSanitizer;
use Modules\Wallet\Support\Payments\TopupQuote;
use Modules\Wallet\Support\RequestHasher;

/**
 * Customer-facing side of an online top-up: validate → quote → start the
 * gateway charge. Nothing here ever touches a wallet balance — money only
 * moves in PaymentCompletionService, after the gateway confirms.
 */
class PaymentTopupService
{
    public function __construct(
        private readonly FeeService $fees,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly PaymentLogger $logger,
        private readonly PaymentCompletionService $completion,
    ) {}

    /**
     * What a top-up of this amount would credit — shown to the customer
     * before they commit, and snapshotted onto the payment when they do.
     */
    public function quote(Model $owner, Country $country, PaymentMethod $method, int $amountMinor): TopupQuote
    {
        $this->assertUsable($country, $method, $amountMinor);

        $rule = $this->fees->resolveRule($country, $method, OwnerType::aliasFor($owner), (int) $owner->getKey());

        return $this->fees->quote($amountMinor, $rule);
    }

    /**
     * Idempotent on `$idempotencyKey` (scoped per owner): the same key + same
     * request returns the original payment untouched; the same key with a
     * different request is a 409.
     *
     * @throws TopupException
     * @throws IdempotencyConflictException
     * @throws PaymentGatewayException
     */
    public function initiate(
        Model $owner,
        Country $country,
        PaymentMethod $method,
        int $amountMinor,
        string $idempotencyKey,
        string $locale = 'en',
    ): PaymentTransaction {
        $alias = OwnerType::aliasFor($owner);
        $scopedKey = "{$alias}:{$owner->getKey()}:{$idempotencyKey}";
        $requestHash = RequestHasher::hash([
            'owner' => "{$alias}:{$owner->getKey()}",
            'country_id' => $country->id,
            'payment_method_id' => $method->id,
            'amount_minor' => $amountMinor,
        ]);

        $existing = PaymentTransaction::query()->where('idempotency_key', $scopedKey)->first();

        if ($existing !== null) {
            return $this->replay($existing, $requestHash, $idempotencyKey);
        }

        $quote = $this->quote($owner, $country, $method, $amountMinor);

        try {
            $payment = PaymentTransaction::query()->create([
                'uuid' => (string) Str::uuid(),
                'payment_method_id' => $method->id,
                'owner_type' => $alias,
                'owner_id' => $owner->getKey(),
                'country_id' => $country->id,
                'currency_id' => $country->currency_id,
                'requested_amount_minor' => $amountMinor,
                'status' => PaymentTransactionStatus::Pending,
                'fee_rule_id' => $quote->rule?->id,
                'fee_percent' => $quote->percent,
                'quoted_net_amount_minor' => $quote->netWithdrawableMinor(),
                'quoted_bonus_amount_minor' => $quote->bonusMinor,
                'expires_at' => now()->addMinutes((int) config('wallet.payments.expiry_minutes', 60)),
                'idempotency_key' => $scopedKey,
                'request_hash' => $requestHash,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two identical requests raced past the lookup above — the loser
            // just becomes a replay of the winner.
            return $this->replay(
                PaymentTransaction::query()->where('idempotency_key', $scopedKey)->firstOrFail(),
                $requestHash,
                $idempotencyKey,
            );
        }

        return $this->startCharge($payment, $method, $owner, $country, $locale);
    }

    /**
     * URPay is not a redirect flow: initiate() texts an OTP, and this second
     * step submits it. A wrong OTP leaves the payment pending so the customer
     * can retry until it expires.
     *
     * @throws TopupException
     */
    public function confirmOtp(PaymentTransaction $payment, string $otp, string $locale = 'en'): PaymentTransaction
    {
        if ($payment->status !== PaymentTransactionStatus::Pending) {
            throw TopupException::paymentNotPending();
        }

        $method = $payment->paymentMethod;
        $gateway = $this->gateways->for($method);
        $credentials = $method->credentials ?? [];

        $result = $gateway->handleCallback(
            new Request(['otp' => $otp]),
            ($payment->gateway_context ?? []) + ['payment_url' => $credentials['payment_url'] ?? null, 'credentials' => $credentials],
        );

        $this->logger->record(
            $payment,
            $method->gateway,
            PaymentGatewayLogEvent::OtpExecute,
            'outbound',
            request: ['otp' => '[redacted]'],
            response: $result->rawResponse,
            gatewayStatus: $result->confirmed ? 'confirmed' : ($result->errorCode ?? 'not_confirmed'),
            externalReference: $result->gatewayReference,
        );

        if (! $result->confirmed) {
            throw TopupException::gatewayRejected($gateway->translateError($result->errorCode, $result->errorMessage, $locale));
        }

        return $this->completion->complete($payment, $result);
    }

    private function startCharge(PaymentTransaction $payment, PaymentMethod $method, Model $owner, Country $country, string $locale): PaymentTransaction
    {
        $gateway = $this->gateways->for($method);
        $callbackUrl = route('api.wallet.payments.callback', ['uuid' => $payment->uuid]);

        try {
            $result = $gateway->initiate(new GatewayChargeRequest(
                credentials: $method->credentials ?? [],
                amountMinor: $payment->requested_amount_minor,
                currencyCode: $country->currency->code,
                customerName: (string) ($owner->name ?? ''),
                customerPhone: $owner->phone ?? null,
                customerEmail: $owner->email ?? null,
                localReference: $payment->uuid,
                successUrl: $callbackUrl,
                errorUrl: $callbackUrl,
                locale: $locale,
                customerDialCode: $country->dial_code,
            ));
        } catch (PaymentGatewayException $e) {
            $this->logger->record($payment, $method->gateway, PaymentGatewayLogEvent::Initiate, 'outbound', gatewayStatus: 'exception');
            $payment->update(['status' => PaymentTransactionStatus::Failed, 'failure_reason' => mb_substr($e->getMessage(), 0, 255)]);

            throw $e;
        }

        $this->logger->record(
            $payment,
            $method->gateway,
            PaymentGatewayLogEvent::Initiate,
            'outbound',
            request: $result->rawRequest,
            response: $result->rawResponse,
            gatewayStatus: $result->success ? 'initiated' : 'rejected',
            externalReference: $result->gatewayReference,
        );

        if (! $result->success) {
            $payment->update([
                'status' => PaymentTransactionStatus::Failed,
                'failure_reason' => mb_substr((string) $result->errorMessage, 0, 255),
                'raw_request' => LogSanitizer::redact($result->rawRequest),
                'raw_response' => LogSanitizer::redact($result->rawResponse),
            ]);

            throw TopupException::gatewayRejected($gateway->translateError(null, $result->errorMessage, $locale));
        }

        $payment->update([
            'gateway_reference' => $result->gatewayReference,
            'redirect_url' => $result->redirectUrl,
            'raw_request' => LogSanitizer::redact($result->rawRequest),
            'raw_response' => LogSanitizer::redact($result->rawResponse),
            'gateway_context' => $result->extra ?: null,
        ]);

        return $payment;
    }

    private function replay(PaymentTransaction $existing, string $requestHash, string $idempotencyKey): PaymentTransaction
    {
        if ($existing->request_hash !== $requestHash) {
            throw new IdempotencyConflictException($idempotencyKey);
        }

        return $existing;
    }

    /**
     * @throws TopupException
     */
    private function assertUsable(Country $country, PaymentMethod $method, int $amountMinor): void
    {
        $available = PaymentMethod::query()
            ->availableForCountry($country)
            ->whereKey($method->id)
            ->where('supports_topup', true)
            ->where('type', PaymentMethodType::Online)
            ->exists();

        if (! $available) {
            throw TopupException::methodUnavailable();
        }

        if (! $method->isConfigured()) {
            throw TopupException::comingSoon();
        }

        // Per-country limits live on the pivot; a global method with no link
        // row for this country simply has no limits.
        $link = $method->countryLinks()->where('country_id', $country->id)->first();

        $min = $link?->min_amount_minor;
        $max = $link?->max_amount_minor;

        if ($amountMinor <= 0 || ($min !== null && $amountMinor < $min) || ($max !== null && $amountMinor > $max)) {
            throw TopupException::amountOutOfRange($min, $max);
        }
    }
}
