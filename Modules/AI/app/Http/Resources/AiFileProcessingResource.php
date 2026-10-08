<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiFileProcessingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file' => $this->whenLoaded('file', fn () => $this->file ? [
                'id' => $this->file->id,
                'file_name' => $this->file->file_name,
            ] : null),
            'file_id' => $this->file_id,
            'processing_type' => $this->processing_type,
            'status' => $this->status,
            'extracted_content_ref' => $this->extracted_content_ref,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
