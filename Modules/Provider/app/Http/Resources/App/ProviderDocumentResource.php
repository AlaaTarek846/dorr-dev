<?php

namespace Modules\Provider\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Provider\Models\ProviderDocument;

/** @mixin ProviderDocument */
class ProviderDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'document_type_id' => $this->document_type_id,
            'code' => $this->whenLoaded('type', fn () => $this->type->code),
            'number' => $this->number,
            'expires_on' => $this->expires_on?->toDateString(),
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'front' => $this->getSingleMediaUrl('front') ?: null,
            'back' => $this->getSingleMediaUrl('back') ?: null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
