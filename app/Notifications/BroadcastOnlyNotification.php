<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Broadcast-only notification (no DB persistence).
 *
 * Use for ephemeral real-time events such as online-status updates,
 * typing indicators, or any event that should NOT be stored in the
 * notifications table.
 *
 * Usage:
 *   $notifiable->notify(new BroadcastOnlyNotification('user.status', $payload));
 */
class BroadcastOnlyNotification extends Notification implements ShouldBroadcast
{
    use Queueable;

    public string $broadcastEvent;

    public mixed $data;

    public string $title;

    public string $message;

    public string $image;

    /**
     * @param  string  $broadcastEvent  Pusher event name
     * @param  mixed  $data  Arbitrary payload forwarded as-is
     * @param  string  $title  Optional title string (already translated)
     * @param  string  $message  Optional message string (already translated)
     * @param  string  $image  Optional image URL
     */
    public function __construct(
        string $broadcastEvent,
        mixed $data = [],
        string $title = '',
        string $message = '',
        string $image = '',
    ) {
        $this->broadcastEvent = $broadcastEvent;
        $this->data = $data;
        $this->title = $title;
        $this->message = $message;
        $this->image = $image;
    }

    /**
     * Only broadcast — nothing stored in DB.
     */
    public function via(mixed $notifiable): array
    {
        return ['broadcast'];
    }

    /**
     * The Pusher event name.
     */
    public function broadcastAs(): string
    {
        return $this->broadcastEvent;
    }

    /**
     * Broadcast payload using the same Dorr API envelope shape.
     */
    public function toBroadcast(mixed $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage([
            'status' => 'Success',
            'code' => 200,
            'message' => 'Success',
            'payload' => $this->data,
            'isSuccess' => true,
        ]))->onConnection('sync');
    }
}
