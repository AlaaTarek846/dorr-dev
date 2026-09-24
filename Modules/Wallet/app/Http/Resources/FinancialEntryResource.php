<?php

namespace Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancialEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $notes = $this->notes;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'category' => [
                'slug' => $this->category?->slug,
                'name' => $this->category?->translatedName() ?? $this->category?->slug,
            ],
            'amount_minor' => $this->amount_minor,
            'currency_code' => $this->currency?->code,
            'country_code' => $this->country?->code,
            'entry_date' => $this->entry_date?->toDateString(),
            'note' => is_array($notes) && isset($notes['key']) ? __($notes['key'], $notes['variables'] ?? []) : $this->description,
            'wallet_transaction_id' => $this->wallet_transaction_id,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
