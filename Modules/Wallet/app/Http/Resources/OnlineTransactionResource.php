<?php

namespace Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin view of a payment. raw_request/raw_response/log payloads were already
 * run through LogSanitizer when they were stored; gateway_context (live
 * gateway tokens) is deliberately never read here.
 */
class OnlineTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => [
                'id' => $this->paymentMethod->id,
                'code' => $this->paymentMethod->code,
                'gateway' => $this->paymentMethod->gateway,
                'name' => $this->paymentMethod->translatedName(),
            ]),
            'country_id' => $this->country_id,
            'currency_code' => $this->whenLoaded('currency', fn () => $this->currency?->code),
            'requested_amount_minor' => $this->requested_amount_minor,
            'fee_minor' => $this->quotedFeeMinor(),
            'bonus_minor' => (int) $this->quoted_bonus_amount_minor,
            'net_withdrawable_minor' => $this->quoted_net_amount_minor,
            'fee_percent' => $this->fee_percent,
            'fee_rule_id' => $this->fee_rule_id,
            'gateway_reference' => $this->gateway_reference,
            'failure_reason' => $this->failure_reason,
            'reconciliation_attempts' => $this->reconciliation_attempts,
            'last_reconciled_at' => $this->last_reconciled_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),

            // Detail-only (loaded by OnlineTransactionService::show).
            'raw_request' => $this->when($this->relationLoaded('logs'), $this->raw_request),
            'raw_response' => $this->when($this->relationLoaded('logs'), $this->raw_response),
            'owner' => $this->when($this->relationLoaded('logs'), fn () => ($owner = $this->owner()) === null ? null : [
                'id' => $owner->getKey(),
                'name' => $owner->name ?? null,
                'phone' => $owner->phone ?? null,
            ]),
            'logs' => $this->whenLoaded('logs', fn () => $this->logs->map(fn ($log) => [
                'id' => $log->id,
                'event' => $log->event->value,
                'direction' => $log->direction,
                'http_status' => $log->http_status,
                'gateway_status_reported' => $log->gateway_status_reported,
                'external_reference' => $log->external_reference,
                'request_payload' => $log->request_payload,
                'response_payload' => $log->response_payload,
                'triggered_by_type' => $log->triggered_by_type,
                'triggered_by_id' => $log->triggered_by_id,
                'created_at' => $log->created_at?->toISOString(),
            ])->values()),
            'wallet_transactions' => $this->whenLoaded('walletTransactions', fn () => $this->walletTransactions->map(fn ($tx) => [
                'uuid' => $tx->uuid,
                'type' => $tx->type->value,
                'direction' => $tx->direction->value,
                'bucket' => $tx->bucket->value,
                'amount_minor' => $tx->amount_minor,
                'created_at' => $tx->created_at?->toISOString(),
            ])->values()),
        ];
    }
}
