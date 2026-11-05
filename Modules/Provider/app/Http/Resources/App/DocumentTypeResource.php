<?php

namespace Modules\Provider\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Provider\Models\DocumentType;

/** @mixin DocumentType */
class DocumentTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'subject' => $this->subject->value,
            'kind' => $this->kind->value,
            'name' => $this->translatedName(),
            'instructions' => $this->translated('instructions'),
            'has_number' => $this->has_number,
            'has_expiry' => $this->has_expiry,
            'needs_back_side' => $this->needs_back_side,
        ];
    }
}
