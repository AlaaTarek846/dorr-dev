<?php

namespace Modules\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Services\MoneyRequestService;
use Modules\Chat\Support\MessageViewContext;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One message as one viewer sees it. A message that was deleted for everyone (or disappeared)
 * keeps its place in the conversation but loses its content.
 *
 * @property ChatMessage $resource
 */
class MessageResource extends JsonResource
{
    public function __construct(ChatMessage $message, private readonly MessageViewContext $context)
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $m = $this->resource;
        $ctx = $this->context;
        $gone = $m->isGone();

        return [
            'id' => $m->uuid,
            'conversation_id' => $ctx->conversation->uuid,
            'type' => $m->type->value,
            'body' => $gone ? null : $m->body,
            'meta' => $gone ? null : $m->meta,
            // View-once files are never listed: the recipient gets them once from POST messages/{m}/open.
            'attachments' => $gone || $m->view_once ? [] : $this->attachments($m),
            'view_once' => $m->view_once,
            'is_silent' => $m->is_silent,
            // Mine: someone opened it · Theirs: I already opened it (it can't be opened again).
            'view_once_opened' => $m->view_once
                ? ($ctx->isMine($m) ? ($ctx->openedByOthers[$m->id] ?? 0) > 0 : isset($ctx->openedByMe[$m->id]))
                : null,
            'link_preview' => $gone ? null : ($m->meta['link_preview'] ?? null),
            // Channels: how many followers saw this post.
            'views' => $ctx->conversation->isChannel() && $m->sender_type !== null ? ($ctx->views[$m->id] ?? 0) : null,
            'poll' => $m->type === MessageType::Poll && ! $gone ? $this->poll($m) : null,
            'live_location' => $m->type === MessageType::Location && ! $gone ? $this->live($m) : null,
            // Money request / bill split, as this viewer sees it (can I pay? my share…).
            'payment' => in_array($m->type, [MessageType::MoneyRequest, MessageType::BillSplit], true) && ! $gone
                ? MoneyRequestService::present($m, $ctx->me, fn ($type, $id) => $ctx->profile($type, $id))
                : null,
            'sender' => $ctx->profile($m->sender_type, $m->sender_id),
            'is_mine' => $ctx->isMine($m),
            // My own message: until when I may still edit it / delete it for everyone (null = no
            // more), so the app only offers what the server will accept. Same rules as MessageService.
            'edit_until' => $ctx->isMine($m) && ! $gone && in_array($m->type, [MessageType::Text, MessageType::Image, MessageType::Video, MessageType::Document], true)
                ? $this->deadline($m, ChatSetting::current()->edit_window_minutes) : null,
            'delete_until' => $ctx->isMine($m) && ! $gone ? $this->deadline($m, ChatSetting::current()->delete_for_everyone_window_minutes) : null,
            'status' => $ctx->statusOf($m),
            'reply_to' => $this->replyPreview($m),
            'is_forwarded' => $m->is_forwarded,
            'forwarded_many_times' => $m->forward_score >= 4,
            'mentions' => array_values(array_filter(array_map(fn ($pid) => $ctx->profileOfParticipant((int) $pid), (array) $m->mentions))),
            'has_link' => $m->has_link && ! $gone,
            'is_edited' => $m->edited_at !== null,
            'is_deleted' => $m->isDeletedForEveryone(),
            'expires_at' => $m->expires_at?->toIso8601String(),
            'reactions' => $this->reactions($m),
            'is_starred' => isset($ctx->starred[$m->id]),
            'system' => $m->sender_type === null ? $this->system($m) : null,
            'created_at' => $m->created_at?->toIso8601String(),
            'edited_at' => $m->edited_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachments(ChatMessage $m): array
    {
        if (! $m->relationLoaded('media')) {
            $m->load('media');
        }

        $poster = $m->getFirstMedia(ChatMessage::THUMBNAIL)?->getUrl();

        return $m->getMedia(ChatMessage::ATTACHMENTS)->sortBy('order_column')->values()->map(fn (Media $media) => [
            'thumbnail' => $m->type === MessageType::Video ? $poster : null,
            'id' => $media->uuid,
            'url' => $media->getUrl(),
            'name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'width' => $media->getCustomProperty('width'),
            'height' => $media->getCustomProperty('height'),
            'duration_ms' => $media->getCustomProperty('duration_ms'),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function poll(ChatMessage $m): array
    {
        $summary = $this->context->polls[$m->id] ?? ['counts' => [], 'voters' => 0];

        return [
            'question' => $m->body,
            'multiple' => (bool) data_get($m->meta, 'multiple'),
            'options' => collect((array) data_get($m->meta, 'options'))->map(fn ($o) => [
                'id' => (int) $o['id'],
                'text' => (string) $o['text'],
                'votes' => (int) ($summary['counts'][(int) $o['id']] ?? 0),
            ])->values()->all(),
            'my_votes' => $this->context->myVotes[$m->id] ?? [],
            'voters' => $summary['voters'],
        ];
    }

    /**
     * @return array{active: bool, live_until: string|null, updated_at: string|null}|null
     */
    private function live(ChatMessage $m): ?array
    {
        $until = data_get($m->meta, 'live_until');

        if ($until === null) {
            return null;
        }

        return [
            'active' => ! data_get($m->meta, 'stopped') && now()->lt($until),
            'live_until' => (string) $until,
            'updated_at' => data_get($m->meta, 'updated_at'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function replyPreview(ChatMessage $m): ?array
    {
        if ($m->reply_to_id === null) {
            return null;
        }

        $reply = $m->relationLoaded('replyTo') ? $m->replyTo : $m->replyTo()->first();

        if ($reply === null) {
            return ['id' => null, 'is_deleted' => true];
        }

        $gone = $reply->isGone();

        return [
            'id' => $reply->uuid,
            'type' => $reply->type->value,
            'body' => $gone ? null : Str::limit((string) $reply->body, 200),
            'sender' => $this->context->profile($reply->sender_type, $reply->sender_id),
            'thumbnail' => $gone ? null : $this->thumbnail($reply),
            'is_deleted' => $gone,
        ];
    }

    private function thumbnail(ChatMessage $m): ?string
    {
        if (! $m->type->hasAttachments() || $m->view_once) {
            return null;
        }

        $media = $m->getFirstMedia(ChatMessage::ATTACHMENTS);

        if ($media !== null && str_starts_with((string) $media->mime_type, 'image/')) {
            return $media->getUrl();
        }

        return $m->getFirstMedia(ChatMessage::THUMBNAIL)?->getUrl();
    }

    /**
     * @return array{summary: list<array{emoji: string, count: int}>, mine: string|null, total: int}
     */
    private function reactions(ChatMessage $m): array
    {
        $reactions = $m->relationLoaded('reactions') ? $m->reactions : $m->reactions()->get();

        return [
            'summary' => $reactions->groupBy('emoji')->map(fn ($rows, $emoji) => ['emoji' => (string) $emoji, 'count' => $rows->count()])
                ->sortByDesc('count')->values()->all(),
            'mine' => $reactions->firstWhere('participant_id', $this->context->me->id)?->emoji,
            'total' => $reactions->count(),
        ];
    }

    /**
     * System line ("Ahmed added Sara"): the app writes the sentence in its own language from
     * `event` + the people involved.
     *
     * @return array<string, mixed>
     */
    private function system(ChatMessage $m): array
    {
        $meta = (array) $m->meta;
        $person = function (?string $key) {
            if ($key === null || ! str_contains($key, ':')) {
                return null;
            }
            [$type, $id] = explode(':', $key, 2);

            return $this->context->profile($type, $id);
        };

        return [
            'event' => $meta['event'] ?? null,
            'actor' => $person($meta['actor'] ?? null),
            'targets' => array_values(array_filter(array_map($person, (array) ($meta['targets'] ?? [])))),
            'text' => __('chat.system.'.($meta['event'] ?? 'unknown'), [
                'actor' => $person($meta['actor'] ?? null)['name'] ?? '',
                'targets' => implode('، ', array_map(fn ($p) => $p['name'] ?? '', array_filter(array_map($person, (array) ($meta['targets'] ?? []))))),
                'name' => $meta['name'] ?? '',
            ]),
        ];
    }

    /** The ISO time a window closes, or null when it already has. */
    private function deadline(ChatMessage $m, int $minutes): ?string
    {
        $until = $m->created_at?->copy()->addMinutes($minutes);

        return $until !== null && $until->isFuture() ? $until->toIso8601String() : null;
    }
}
