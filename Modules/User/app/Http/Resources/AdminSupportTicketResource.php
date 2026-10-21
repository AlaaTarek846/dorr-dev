<?php

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Models\SupportTicket;

/** The ticket as the support team (dashboard) sees it: with the customer and the assigned agent. */
/** @mixin SupportTicket */
class AdminSupportTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title,
            // R-127: the ride it is about, when it is about one.
            'ride' => $this->rideRef(),
            'status' => $this->status->value,
            'accepts_replies' => $this->status->acceptsReplies(),
            // The customer asked for a person: no more automatic replies on this ticket.
            'auto_reply_stopped' => $this->auto_reply_stopped_at !== null,
            // D-285: a captain's ticket shows the captain where the rider would be.
            'owner_type' => $this->driver_id !== null ? 'driver' : 'user',
            'category' => $this->category,
            'reference' => $this->reference,
            'user' => $this->driver_id !== null ? ($this->driver === null ? null : ['id' => $this->driver->id, 'name' => $this->driver->name, 'phone' => $this->driver->phone, 'is_driver' => true]) : $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
            ]),
            'admin' => $this->whenLoaded('admin', fn () => $this->admin === null ? null : [
                'id' => $this->admin->id,
                'name' => $this->admin->name,
            ]),
            'last_message' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage === null ? null : [
                'sender' => $this->latestMessage->sender,
                'body' => $this->latestMessage->body,
                'has_image' => $this->latestMessage->image_path !== null,
                'created_at' => $this->latestMessage->created_at?->toIso8601String(),
            ]),
            'last_message_at' => ($this->last_message_at ?? $this->created_at)?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
