<?php

namespace Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Wallet\Support\MaskedName;

/**
 * One ledger row as the customer/admin sees it. `notes` is stored as a
 * translation key + variables (so history follows the viewer's language, not
 * the language of whoever caused the row) and is translated here, at read time.
 */
class WalletTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $notes = $this->notes;
        $note = is_array($notes) && isset($notes['key']) ? __($notes['key'], $notes['variables'] ?? []) : null;

        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'type_label' => __('wallet.types.'.$this->type->value),
            'direction' => $this->direction->value,
            'bucket' => $this->bucket->value,
            'amount_minor' => $this->amount_minor,
            // Balance of *this row's bucket* after it, and the wallet total.
            'balance_after_minor' => $this->balance_after_minor,
            'total_balance_after_minor' => $this->total_balance_after_minor,
            'note' => $note,
            // Only transfers have one: who is on the other side (phone masked).
            'counterparty' => $this->whenLoaded('counterpartyWallet', fn () => $this->counterpartyWallet === null ? null : $this->counterparty()),
            'payment_transaction_id' => $this->payment_transaction_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * @return array{name: string|null, phone: string|null}|null
     */
    private function counterparty(): ?array
    {
        $owner = $this->counterpartyWallet->owner();

        if ($owner === null) {
            return null;
        }

        $phone = (string) ($owner->phone ?? '');

        return [
            // Another person's name is never shown in full — first letter of each word only.
            'name' => MaskedName::of($owner->name ?? null),
            'phone' => $phone === '' ? null : str_repeat('•', max(0, strlen($phone) - 4)).substr($phone, -4),
        ];
    }
}
