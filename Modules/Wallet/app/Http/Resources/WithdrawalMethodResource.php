<?php

namespace Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-facing: only the masked display — the encrypted `data` is never read
 * here, the same "don't touch the secret at all" rule as PaymentMethodResource.
 */
class WithdrawalMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->label,
            'display' => $this->maskedDisplay(),
            'is_favorite' => (bool) $this->is_favorite,
            'status' => (bool) $this->status,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
