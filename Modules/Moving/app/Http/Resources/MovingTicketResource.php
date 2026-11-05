<?php

namespace Modules\Moving\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Moving\Models\MovingTicket;

/** A ticket about a move with its messages (REQ-062 → REQ-064, REQ-103). @mixin MovingTicket */
class MovingTicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'request_uuid' => $this->request?->uuid,
            'type' => $this->type,
            'subject' => $this->subject,
            'status' => $this->status,
            'escalation_reason' => $this->escalation_reason,
            'closing_reason' => $this->closing_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($m) => [
                'author' => $m->author_type, 'body' => $m->body, 'at' => $m->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
