<?php

namespace Modules\Provider\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'national_id' => $this->national_id,
            'commercial_register_no' => $this->commercial_register_no,
            'id_document_url' => $this->id_document_path,
            'license_document_url' => $this->license_document_path,
            'status' => $this->status?->value ?? $this->status,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at?->toISOString(),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'country' => $this->user->relationLoaded('country') && $this->user->country ? [
                    'id' => $this->user->country->id,
                    'code' => $this->user->country->code,
                ] : null,
            ] : null),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'services' => ProviderServiceResource::collection($this->whenLoaded('services')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
