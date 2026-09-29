<?php

namespace Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One resource for owner and admin. The owner only ever gets the masked
 * method; the admin — who has to actually pay it — gets the full payout
 * details, and only on the single-request views (route has the request id),
 * never in a list.
 */
class WithdrawalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = str_contains($request->path(), '/admin/');
        $isDetail = $request->route('withdrawal_request') !== null;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'amount_minor' => $this->amount_minor,
            'currency_code' => $this->whenLoaded('wallet', fn () => $this->wallet->currency?->code),
            'method' => $this->whenLoaded('method', fn () => [
                'type' => $this->method->type->value,
                'label' => $this->method->label,
                'display' => $this->method->maskedDisplay(),
            ]),
            'note' => $this->note,
            'rejection_reason' => $this->rejection_reason,
            'has_receipt' => $this->getFirstMedia('receipt') !== null,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),

            // Admin-only, single request only.
            'owner' => $this->when($isAdmin, fn () => ($owner = $this->wallet?->owner()) === null ? null : [
                'type' => $this->wallet->owner_type,
                'id' => $owner->getKey(),
                'name' => $owner->name ?? null,
                'phone' => $owner->phone ?? null,
            ]),
            'country_code' => $this->when($isAdmin, fn () => $this->wallet?->country?->code),
            'payout_details' => $this->when($isAdmin && $isDetail && $this->relationLoaded('method'), fn () => $this->method->data),
        ];
    }
}
