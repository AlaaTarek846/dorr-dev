<?php

namespace Modules\Freelance\Services\Chat;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Freelance\Events\FreelanceMessageSent;
use Modules\Freelance\Events\FreelanceMessagesRead;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceMessage;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\User\Models\User;
use Throwable;

/**
 * The client-freelancer chat (FL-006): its own small chat, apart from Modules/Chat. One room per
 * client + freelancer + context (a project), opened by the server only; only its two people can read
 * or write. Text, images and files; live to the other side over a private channel and by push —
 * no polling. A room goes read-only when the work ends, or when the `chat` switch is off in the
 * freelancer's country.
 */
class FreelanceChatService
{
    /** How long the sender may take a message back. */
    public const DELETE_WINDOW_MINUTES = 5;

    public const BODY_MAX = 2000;

    public function __construct(
        private readonly NotificationCenter $notifications,
        private readonly FreelanceFeatures $features,
    ) {}

    // -------------------------------------------------------------- rooms

    /** The room for this context, created the first time (called by the project / offer services, never by an app). */
    public function open(User $client, Freelancer $freelancer, string $contextType, int $contextId): FreelanceConversation
    {
        return FreelanceConversation::query()->firstOrCreate(
            ['client_id' => $client->id, 'freelancer_id' => $freelancer->id, 'context_type' => $contextType, 'context_id' => $contextId],
            ['status' => FreelanceConversation::OPEN],
        );
    }

    /** Ends the writing (the work is over, or a dispute is open); the history stays for both and for the admin. */
    public function setStatus(FreelanceConversation $conversation, string $status): void
    {
        $conversation->forceFill(['status' => $status])->save();
    }

    /** @throws ModelNotFoundException a room that isn't yours looks like it doesn't exist */
    public function forParty(Model $me, string $uuid): FreelanceConversation
    {
        return FreelanceConversation::query()
            ->where('uuid', $uuid)
            ->where(self::side($me) === 'client' ? 'client_id' : 'freelancer_id', $me->getKey())
            ->with(['client', 'freelancer'])
            ->firstOrFail();
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, FreelanceConversation> */
    public function list(Model $me, int $perPage = 20)
    {
        $side = self::side($me);

        return FreelanceConversation::query()
            ->where($side === 'client' ? 'client_id' : 'freelancer_id', $me->getKey())
            ->with(['client', 'freelancer'])
            ->orderByRaw('last_message_at IS NULL')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function canWrite(FreelanceConversation $conversation): bool
    {
        if (! $conversation->isOpen()) {
            return false;
        }

        $countryId = $conversation->freelancer?->country_id;

        return $countryId !== null && $this->features->allows($countryId, 'chat');
    }

    // -------------------------------------------------------------- messages

    /** @return Collection<int, FreelanceMessage> newest first; `before` is the id to continue from */
    public function messages(FreelanceConversation $conversation, int $limit = 30, ?int $beforeId = null): Collection
    {
        return FreelanceMessage::query()
            ->where('conversation_id', $conversation->id)
            ->when($beforeId !== null, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @return array<string, mixed> */
    public function send(FreelanceConversation $conversation, Model $sender, ?string $body, ?UploadedFile $file, ?string $clientId): array
    {
        if (! $this->canWrite($conversation)) {
            throw new FreelanceException('chat_closed', 409);
        }

        $side = self::side($sender);
        $text = trim((string) $body);
        if ($text === '' && $file === null) {
            throw new FreelanceException('chat_empty', 422);
        }

        try {
            $message = DB::transaction(function () use ($conversation, $sender, $side, $text, $file, $clientId): FreelanceMessage {
                $message = FreelanceMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender_type' => $side,
                    'sender_id' => $sender->getKey(),
                    'kind' => $file === null ? 'text' : (str_starts_with((string) $file->getMimeType(), 'image/') ? 'image' : 'file'),
                    'body' => $text === '' ? null : mb_substr($text, 0, self::BODY_MAX),
                    'client_id' => $clientId,
                    'created_at' => now(),
                ]);
                if ($file !== null) {
                    $message->setSingleMedia('attachment', $file);
                }

                $conversation->forceFill([
                    'last_message_at' => $message->created_at,
                    'last_message_preview' => mb_substr($text !== '' ? $text : ($message->kind === 'image' ? '📷' : '📎'), 0, 120),
                ])->save();
                $conversation->increment($side === 'client' ? 'freelancer_unread' : 'client_unread');

                return $message;
            });
        } catch (QueryException) {
            // The same message sent again (a weak network): the one already stored.
            $message = FreelanceMessage::query()->where('conversation_id', $conversation->id)->where('sender_type', $side)->where('client_id', $clientId)->first();
            if ($message === null) {
                throw new FreelanceException('chat_empty', 422);
            }

            return self::present($message, $side);
        }

        $this->deliver($conversation, $message, $side);

        return self::present($message, $side);
    }

    /** Marks everything the other side wrote as read, and tells them so live. */
    public function markRead(FreelanceConversation $conversation, Model $reader): int
    {
        $side = self::side($reader);
        $other = $side === 'client' ? 'freelancer' : 'client';

        $upTo = (int) FreelanceMessage::query()->where('conversation_id', $conversation->id)->where('sender_type', $other)->max('id');
        $count = FreelanceMessage::query()->where('conversation_id', $conversation->id)->where('sender_type', $other)->whereNull('read_at')->update(['read_at' => now()]);
        $conversation->forceFill([$side === 'client' ? 'client_unread' : 'freelancer_unread' => 0])->save();

        if ($count > 0) {
            $this->broadcast(new FreelanceMessagesRead($conversation->uuid, $side, $upTo));
        }

        return $count;
    }

    /** The sender takes a message back within a few minutes; it stays in the room as "deleted". */
    public function delete(Model $me, int $messageId): void
    {
        $message = FreelanceMessage::query()->with('conversation')->findOrFail($messageId);
        $side = self::side($me);
        $conversation = $message->conversation;

        $mine = $message->sender_type === $side && (int) $message->sender_id === (int) $me->getKey()
            && (int) $conversation->{$side === 'client' ? 'client_id' : 'freelancer_id'} === (int) $me->getKey();
        if (! $mine) {
            throw new ModelNotFoundException;
        }
        if ($message->deleted_at !== null || $message->created_at->lt(now()->subMinutes(self::DELETE_WINDOW_MINUTES)) || ! $this->canWrite($conversation)) {
            throw new FreelanceException('message_not_deletable', 422);
        }

        $message->clearMediaCollection('attachment');
        $message->forceFill(['body' => null, 'deleted_at' => now()])->save();
        $this->broadcast(new FreelanceMessageSent($conversation->uuid, self::present($message, null)));
    }

    // -------------------------------------------------------------- delivery

    private function deliver(FreelanceConversation $conversation, FreelanceMessage $message, string $side): void
    {
        $this->broadcast(new FreelanceMessageSent($conversation->uuid, self::present($message, null)));

        $receiver = $side === 'client' ? $conversation->freelancer : $conversation->client;
        $senderName = $side === 'client' ? (string) $conversation->client?->name : (string) $conversation->freelancer?->name;

        // The freelancer gets push once their devices exist (FL-007); until then the live channel covers them.
        if ($receiver !== null && method_exists($receiver, 'notificationDevices')) {
            $this->notifications->send($receiver, 'freelance.message', 'freelance_message_title', 'freelance_message_body', [
                'name' => strtok($senderName, ' ') ?: $senderName,
                'text' => mb_substr($message->body ?? '', 0, 120) ?: ($message->kind === 'image' ? '📷' : '📎'),
            ], ['type' => 'freelance_message', 'conversation_uuid' => $conversation->uuid]);
        }
    }

    private function broadcast(object $event): void
    {
        DB::afterCommit(function () use ($event): void {
            try {
                broadcast($event);
            } catch (Throwable $e) {
                Log::warning('[Freelance] chat broadcast failed: '.$e->getMessage());
            }
        });
    }

    // -------------------------------------------------------------- shape

    /** @return array<string, mixed> */
    public static function present(FreelanceMessage $message, ?string $viewer): array
    {
        $deleted = $message->deleted_at !== null;
        $media = $deleted ? null : $message->getSingleMedia('attachment');

        return [
            'id' => $message->id,
            'from' => $message->sender_type,
            'mine' => $viewer !== null ? $message->sender_type === $viewer : null,
            'kind' => $deleted ? 'deleted' : $message->kind,
            'body' => $deleted ? null : $message->body,
            'attachment' => $media === null ? null : [
                'url' => $message->getSingleMediaOriginalUrl('attachment'),
                'name' => $media->file_name,
                'mime' => $media->mime_type,
                'size' => $media->size,
            ],
            'client_id' => $message->client_id,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentConversation(FreelanceConversation $conversation, Model $viewer): array
    {
        $side = self::side($viewer);
        $peer = $side === 'client' ? $conversation->freelancer : $conversation->client;

        return [
            'uuid' => $conversation->uuid,
            'context' => ['type' => $conversation->context_type, 'id' => $conversation->context_id],
            'status' => $conversation->status,
            'can_write' => $this->canWrite($conversation),
            'peer' => [
                'name' => $peer?->name,
                'avatar_url' => $peer !== null && method_exists($peer, 'getSingleMediaUrl') ? ($peer->getSingleMediaUrl('avatar') ?: null) : null,
            ],
            'unread' => (int) $conversation->{$side === 'client' ? 'client_unread' : 'freelancer_unread'},
            'last_message_preview' => $conversation->last_message_preview,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
        ];
    }

    /** 'client' for a Dorr user, 'freelancer' for a freelancer. */
    public static function side(Model $person): string
    {
        return $person instanceof Freelancer ? 'freelancer' : 'client';
    }
}
