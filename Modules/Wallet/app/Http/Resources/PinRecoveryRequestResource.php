<?php

namespace Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One PIN recovery request as the reviewer sees it: who asked, which document, and where to fetch the
 * two photos (through an authenticated endpoint — they are personal documents, never public URLs).
 */
class PinRecoveryRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $owner = $this->owner();
        $base = '/api/admin/v1/pin-recovery-requests/'.$this->id;

        return [
            'id' => $this->id,
            'method' => $this->method->value,
            'reason' => $this->reason->value,
            'status' => $this->status->value,
            'owner' => [
                'type' => $this->owner_type,
                'id' => $this->owner_id,
                'name' => $owner?->name,
                'phone' => $owner?->phone,
                'email' => $owner?->email,
            ],
            'original_image' => $this->getFirstMedia('original_document') !== null ? $base.'/image/original' : null,
            'new_image' => $this->getFirstMedia('new_document') !== null ? $base.'/image/new' : null,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
