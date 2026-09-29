<?php

namespace App\Notifications;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * General-purpose notification that stores in DB and broadcasts via Pusher.
 *
 * Usage (from helper):
 *   $notifiable->notify(new GeneralNotification(...))
 *
 * Title/message handling:
 *   - $type = null   → $title/$message are translation keys → resolved via __('notifications.{key}', $variables, $locale)
 *   - $type = 'raw'  → $title/$message are already-translated arrays ['ar' => '...', 'en' => '...', ...]
 *                       (used when sending from dashboard with pre-translated text)
 */
class GeneralNotification extends Notification implements ShouldBroadcast
{
    public mixed $data;

    public string|array $title;

    public string|array $message;

    public array $variables;

    public string $image;

    public string $broadcastEvent;

    public string $status;

    /**
     * null  → title/message are translation keys
     * 'raw' → title/message are pre-translated arrays keyed by locale
     */
    public ?string $type;

    /**
     * @param  string  $broadcastEvent  Pusher event name (e.g. "order.status")
     * @param  mixed  $data  Model or array to attach as payload
     * @param  string  $image  Notification image URL
     * @param  string|array  $title  Translation key (type=null) or ['ar'=>'', 'en'=>'', ...] (type='raw')
     * @param  string|array  $message  Translation key (type=null) or ['ar'=>'', 'en'=>'', ...] (type='raw')
     * @param  array  $variables  Replacement variables for the translation (type=null only)
     * @param  string  $status  'Success' | 'Error'
     * @param  string|null  $type  null or 'raw'
     */
    public function __construct(
        string $broadcastEvent,
        mixed $data = [],
        string $image = '',
        string|array $title = '',
        string|array $message = '',
        array $variables = [],
        string $status = 'Success',
        ?string $type = null,
    ) {
        $this->broadcastEvent = $broadcastEvent;
        $this->data = $data;
        $this->image = $image;
        $this->title = $title;
        $this->message = $message;
        $this->variables = $variables;
        $this->status = $status;
        $this->type = $type;
    }

    /**
     * Delivery channels: store in DB + push via Pusher.
     */
    public function via(mixed $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * The Pusher event name.
     */
    public function broadcastAs(): string
    {
        return $this->broadcastEvent;
    }

    /**
     * Database representation.
     * Stores raw keys/arrays so that the frontend can render in any locale.
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'timeDate' => now()->format('Y-m-d H:i'),
            'title' => $this->title,
            'message' => $this->message,
            'variables' => $this->variables,
            'image' => $this->image,
            'type' => $this->type,
            // What the app needs to open the right screen (ids only — never secrets).
            'event' => $this->broadcastEvent,
            'data' => $this->storableData(),
        ];
    }

    /**
     * The payload as plain data for the notifications table (a model/resource can't be stored as-is).
     *
     * @return array<string, mixed>
     */
    private function storableData(): array
    {
        if (is_array($this->data)) {
            return $this->data;
        }

        return $this->data instanceof \Illuminate\Contracts\Support\Arrayable ? $this->data->toArray() : [];
    }

    /**
     * Broadcast payload.
     * Resolves the locale from the notifiable model (multi-lang support),
     * then falls back to the app locale.
     */
    public function toBroadcast(mixed $notifiable): BroadcastMessage
    {
        // Resolve locale: notifiable model may have a `locale` or `language` attribute.
        $locale = $notifiable?->locale
            ?? $notifiable?->language
            ?? app()->getLocale();

        if ($this->type === 'raw') {
            // Pre-translated arrays keyed by locale (sent from dashboard)
            $title = is_array($this->title) ? ($this->title[$locale] ?? $this->title[array_key_first($this->title)] ?? '') : $this->title;
            $message = is_array($this->message) ? ($this->message[$locale] ?? $this->message[array_key_first($this->message)] ?? '') : $this->message;
        } else {
            // Translation keys resolved per notifiable locale
            $title = __('notifications.'.$this->title, $this->variables, $locale);
            $message = __('notifications.'.$this->message, $this->variables, $locale);
        }

        $id = 0;
        if ($this->data) {
            $id = is_array($this->data)
                ? ($this->data['id'] ?? 0)
                : ($this->data?->id ?? 0);
        }

        return (new BroadcastMessage([
            'data' => [
                'status' => $this->status,
                'code' => 200,
                'message' => $this->status,
                'payload' => [
                    'timeDate' => now()->format('Y-m-d H:i'),
                    'title' => $title,
                    'message' => $message,
                    'id' => $id,
                    'data' => is_array($this->data) && count($this->data) === 0 ? ['id' => 0] : $this->data,
                    'image' => $this->image,
                ],
                'isSuccess' => true,
            ],
        ]))->onConnection('sync');
    }
}
