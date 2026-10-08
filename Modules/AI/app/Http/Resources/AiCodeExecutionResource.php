<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiCodeExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'conversation_id' => $this->conversation_id,
            'language' => $this->language,
            'driver' => $this->driver,
            'code' => $this->code,
            'status' => $this->status,
            'exit_code' => $this->exit_code,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'duration_ms' => $this->duration_ms,
            'attempt_number' => $this->attempt_number,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
