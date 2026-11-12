<?php

namespace Modules\Marketing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Never the token: only whether the account is usable and what to do next. */
class IntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'provider' => $this->provider,
            'status' => $this->status,
            'account' => $this->external_account_ref === null ? null : ['id' => $this->external_account_ref, 'name' => $this->external_account_name, 'currency' => $this->account_currency],
            'available_accounts' => $this->available_accounts,
            'scopes' => $this->scopes ?? [],
            'sandbox' => $this->sandbox,
            'token_expires_at' => $this->token_expires_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'reconnect_needed' => in_array($this->status, ['EXPIRED', 'ERROR', 'REVOKED'], true),
        ];
    }
}
