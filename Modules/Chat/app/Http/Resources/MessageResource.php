<?php

namespace Modules\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\MessageExtrasService;
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
            'attachments' => $gone ? [] : $this->attachments($m),
            'sender' => $ctx->profile($m->sender_type, $m->sender_id),
            'is_mine' => $ctx->isMine($m),
            'status' => $ctx->statusOf($m),
            'reply_to' => $this->replyPreview($m),
            'is_forwarded' => $m->is_forwarded,
            'forwarded_many_times' => $m->forward_score >= 4,
            'mentions' => array_values(array_filter(array_map(fn ($pid) => $ctx->profileOfParticipant((int) $pid), (array) $m->mentions))),
            'has_link' => $m->has_link && ! $gone,
            'is_edited' => $m->edited_at !== null,
            'is_deleted' => $m->isDeletedForEveryone(),
            'expires_at' => $m->expires_at?->toIso8601String(),
            'link_preview' => $gone ? null : $this->linkPreview($m),
            'reactions' => $this->reactions($m),
            'is_starred' => isset($ctx->starred[$m->id]),
            'poll' => $gone ? null : $this->poll($m),
            'payment' => $gone ? null : $this->payment($m),
            'view_once' => ! $gone && (bool) $m->view_once,
            'view_once_opened' => isset($ctx->opened[$m->id]),
            // Its files, once the recipient opens it.
            'live_location' => $gone ? null : $this->liveLocation($m),
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
        // A view-once file is only handed out by MessageExtrasService::open(), once, to the
        // recipient who opens it — never listed here.
        if ($m->view_once) {
            return [];
        }

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
        if (! $m->type->hasAttachments()) {
            return null;
        }

        $media = $m->getFirstMedia(ChatMessage::ATTACHMENTS);

        if ($media !== null && str_starts_with((string) $media->mime_type, 'image/')) {
            return $media->getUrl();
        }

        return $m->getFirstMedia(ChatMessage::THUMBNAIL)?->getUrl();
    }

    /**
     * The poll as this viewer sees it: the options saved on the message, each with how many people
     * ticked it, and which ones I ticked. Null for anything that is not a poll.
     *
     * @return array{question: string|null, multiple: bool, options: list<array{id: int, text: string, votes: int}>, voters: int, my_votes: list<int>}|null
     */
    private function poll(ChatMessage $m): ?array
    {
        if ($m->type !== MessageType::Poll) {
            return null;
        }

        $meta = (array) $m->meta;
        $summary = MessageExtrasService::pollSummary($m->id);

        return [
            'question' => $m->body,
            'multiple' => (bool) ($meta['multiple'] ?? false),
            'options' => array_values(array_map(
                fn (array $option) => [
                    'id' => (int) $option['id'],
                    'text' => (string) $option['text'],
                    'votes' => (int) ($summary['counts'][(int) $option['id']] ?? 0),
                ],
                (array) ($meta['options'] ?? []),
            )),
            'voters' => $summary['voters'],
            'my_votes' => $this->context->myVotes[$m->id] ?? [],
        ];
    }

    /**
     * A money request or a split bill, priced in the viewer's own currency.
     *
     * @return array<string, mixed>|null
     */
    private function payment(ChatMessage $m): ?array
    {
        if ($m->type !== MessageType::MoneyRequest && $m->type !== MessageType::BillSplit) {
            return null;
        }

        return MoneyRequestService::present($m, $this->context->me, fn (string $type, int $id) => $this->context->profile($type, $id));
    }

    /**
     * The position of a live location, while it is still running. Null for a location that was
     * never live, has run out, or was stopped.
     *
     * @return array{latitude: float, longitude: float, accuracy: float|null, live_until: string, updated_at: string|null}|null
     */
    private function liveLocation(ChatMessage $m): ?array
    {
        if ($m->type !== MessageType::Location) {
            return null;
        }

        $until = data_get($m->meta, 'live_until');
        if ($until === null) {
            return null;
        }

        // A live location keeps its last position once it is stopped or runs out, so the app can
        // still draw where it was; `active` is what tells it to stop posting moves.
        return [
            'active' => ! data_get($m->meta, 'stopped') && now()->lt($until),
            'latitude' => (float) data_get($m->meta, 'latitude'),
            'longitude' => (float) data_get($m->meta, 'longitude'),
            'accuracy' => data_get($m->meta, 'accuracy') !== null ? (float) data_get($m->meta, 'accuracy') : null,
            'live_until' => (string) $until,
            'updated_at' => data_get($m->meta, 'updated_at'),
        ];
    }

    /**
     * The card for this message's first link, cached into its meta when the message was sent or
     * edited (LinkPreviewService::attachTo), so reading a page never fetches anything.
     *
     * @return array<string, mixed>|null
     */
    private function linkPreview(ChatMessage $m): ?array
    {
        $card = data_get($m->meta, 'link_preview');

        return is_array($card) ? $card : null;
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
}
