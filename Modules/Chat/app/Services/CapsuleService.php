<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMomentCapsule;
use Modules\Chat\Models\ChatMomentCapsuleItem;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * Moment capsules (spec 166): albums of things I chose to keep for an occasion. Nothing is pulled
 * from a chat on its own — I add each item, and it's copied (text and files) so it stays even if
 * the chat's copy disappears.
 */
class CapsuleService
{
    public const MAX_ITEMS = 300;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ParticipantDirectory $directory,
    ) {}

    /**
     * @param  array<string, mixed>  $data  title, emoji, moment_id
     */
    public function create(Model $me, array $data): ChatMomentCapsule
    {
        if (ChatMomentCapsule::query()->ownedBy($me)->count() >= 100) {
            throw new ChatException('capsule_limit', 422, ['max' => 100]);
        }

        return ChatMomentCapsule::query()->create([
            'uuid' => (string) Str::uuid(),
            'owner_type' => ParticipantType::aliasFor($me),
            'owner_id' => $me->getKey(),
            'title' => mb_substr(trim((string) $data['title']), 0, 120),
            'emoji' => $data['emoji'] ?? null,
            'chat_moment_id' => $data['moment_id'] ?? null,
        ]);
    }

    /** Keep a copy of one message I can see — its words and its files. */
    public function add(Model $me, ChatMomentCapsule $capsule, string $messageUuid, ?string $note): ChatMomentCapsuleItem
    {
        $this->assertMine($me, $capsule);
        if ($capsule->items()->count() >= self::MAX_ITEMS) {
            throw new ChatException('capsule_full', 422, ['max' => self::MAX_ITEMS]);
        }

        $message = ChatMessage::query()->where('uuid', $messageUuid)->with(['conversation', 'media'])->first() ?? throw new ChatException('message_not_found', 404);
        $participant = $this->conversations->participantOf($me, $message->conversation);
        if ($message->isGone() || $message->view_once || ! $this->messages->visibleTo($participant, ChatMessage::query()->whereKey($message->id))->exists()) {
            throw ChatException::messageDeleted();
        }

        if ($existing = $capsule->items()->where('source_message_id', $message->uuid)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($me, $capsule, $message, $note) {
            $item = $capsule->items()->create([
                'type' => $message->type->value,
                'text' => $message->body,
                'sender_name' => $message->sender_type ? ($this->directory->profile($me, $message->sender_type, $message->sender_id)['name'] ?? null) : null,
                'note' => $note !== null ? (mb_substr(trim($note), 0, 300) ?: null) : null,
                'source_message_id' => $message->uuid,
                'original_at' => $message->created_at,
            ]);
            foreach ($message->getMedia(ChatMessage::ATTACHMENTS) as $media) {
                $media->copy($item, ChatMessage::ATTACHMENTS);
            }
            $capsule->touch();

            return $item;
        });
    }

    public function remove(Model $me, ChatMomentCapsule $capsule, int $itemId): void
    {
        $this->assertMine($me, $capsule);
        $item = $capsule->items()->find($itemId) ?? throw new ChatException('message_not_found', 404);
        $item->clearMediaCollection(ChatMessage::ATTACHMENTS);
        $item->delete();
    }

    public function delete(Model $me, ChatMomentCapsule $capsule): void
    {
        $this->assertMine($me, $capsule);
        $capsule->items->each(fn ($i) => $i->clearMediaCollection(ChatMessage::ATTACHMENTS));
        $capsule->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ChatMomentCapsule $capsule, bool $withItems = false): array
    {
        $row = [
            'id' => $capsule->uuid,
            'title' => $capsule->title,
            'emoji' => $capsule->emoji,
            'moment_id' => $capsule->chat_moment_id,
            'items_count' => $capsule->items_count ?? $capsule->items()->count(),
            'updated_at' => $capsule->updated_at?->toIso8601String(),
        ];

        if ($withItems) {
            $row['items'] = $capsule->items()->with('media')->latest('original_at')->get()->map(fn (ChatMomentCapsuleItem $i) => [
                'id' => $i->id,
                'type' => $i->type,
                'text' => $i->text,
                'sender_name' => $i->sender_name,
                'note' => $i->note,
                'original_at' => $i->original_at?->toIso8601String(),
                'files' => $i->getMedia(ChatMessage::ATTACHMENTS)->map(fn ($m) => ['url' => $m->getUrl(), 'mime_type' => $m->mime_type])->values()->all(),
            ])->all();
        }

        return $row;
    }

    public function assertMine(Model $me, ChatMomentCapsule $capsule): void
    {
        if (! $capsule->isOwnedBy($me)) {
            throw new ChatException('capsule_not_found', 404);
        }
    }
}
