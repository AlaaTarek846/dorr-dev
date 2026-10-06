<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatBroadcastList;
use Modules\Chat\Models\ChatBroadcastMessage;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;
use Modules\User\Models\User;
use Throwable;

/**
 * Broadcast lists (like WhatsApp's): the same message to many people, each one getting it in our
 * own one-to-one chat — nobody sees who else got it, and their answers come back to that chat.
 * Like WhatsApp, only people who have me saved receive it (a broadcast is never a way to reach
 * strangers); the others are listed as skipped. Files are uploaded once and copied to the rest.
 */
class BroadcastService
{
    public const MAX_MEMBERS = 256;

    public const MAX_LISTS = 50;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ChatPrivacy $privacy,
        private readonly ParticipantDirectory $directory,
    ) {}

    /**
     * @param  list<int>  $memberIds  user ids
     */
    public function create(Model $me, ?string $name, array $memberIds): ChatBroadcastList
    {
        if (ChatBroadcastList::query()->ownedBy($me)->count() >= self::MAX_LISTS) {
            throw new ChatException('broadcast_list_limit', 422, ['max' => self::MAX_LISTS]);
        }

        return DB::transaction(function () use ($me, $name, $memberIds) {
            $list = ChatBroadcastList::query()->create([
                'uuid' => (string) Str::uuid(),
                'owner_type' => ParticipantType::aliasFor($me),
                'owner_id' => $me->getKey(),
                'name' => $this->cleanName($name),
            ]);
            $this->syncMembers($me, $list, $memberIds);

            return $list;
        });
    }

    /**
     * @param  list<int>|null  $memberIds  null = unchanged
     */
    public function update(Model $me, ChatBroadcastList $list, ?string $name, ?array $memberIds, bool $nameGiven): ChatBroadcastList
    {
        $this->assertMine($me, $list);

        DB::transaction(function () use ($me, $list, $name, $memberIds, $nameGiven) {
            if ($nameGiven) {
                $list->update(['name' => $this->cleanName($name)]);
            }
            if ($memberIds !== null) {
                $this->syncMembers($me, $list, $memberIds);
            }
        });

        return $list->refresh();
    }

    public function delete(Model $me, ChatBroadcastList $list): void
    {
        $this->assertMine($me, $list);
        $list->delete();
    }

    /**
     * Send one message to everyone on the list.
     *
     * @param  array<string, mixed>  $data  as for a normal message (type, body, …)
     * @param  list<UploadedFile>  $files
     * @return array{sent: int, skipped: list<array<string, mixed>>, broadcast: array<string, mixed>}
     */
    public function send(Model $me, ChatBroadcastList $list, array $data, array $files): array
    {
        $this->assertMine($me, $list);
        $members = $list->members()->get();

        if ($members->isEmpty()) {
            throw new ChatException('broadcast_empty', 422);
        }

        // Only plain content goes out to many (no money, no polls, no live location…).
        $type = MessageType::from($data['type'] ?? MessageType::Text->value);
        if (! in_array($type, [MessageType::Text, MessageType::Image, MessageType::Video, MessageType::Voice, MessageType::Audio, MessageType::Document, MessageType::Location, MessageType::Contact, MessageType::Gif, MessageType::Sticker], true)) {
            throw new ChatException('broadcast_type_unsupported', 422);
        }

        $accounts = User::query()->whereIn('id', $members->where('participant_type', 'user')->pluck('participant_id'))->get()->keyBy('id');
        $first = null;
        $sentIds = [];
        $skipped = [];

        foreach ($members as $member) {
            $account = $member->participant_type === 'user' ? $accounts->get($member->participant_id) : null;

            // Like WhatsApp: it reaches only people who saved me.
            if ($account === null || ! $this->privacy->hasInContacts($account, $me) || $this->privacy->isBlockedBetween($me, $account)) {
                $skipped[] = $member;

                continue;
            }

            try {
                $chat = $this->conversations->openDirect($me, $account)->conversation;
                $message = $this->messages->send($me, $chat, collect($data)->except(['uuid', 'reply_to'])->all() + ($first !== null ? ['copy_media_from' => $first] : []), $first === null ? $files : []);
                $first ??= $message;
                $sentIds[] = $message->uuid;
            } catch (Throwable) {
                $skipped[] = $member;
            }
        }

        $row = ChatBroadcastMessage::query()->create([
            'list_id' => $list->id,
            'type' => $type->value,
            'body' => isset($data['body']) ? Str::limit(trim((string) $data['body']), 2000) : null,
            'message_ids' => $sentIds,
            'sent_count' => count($sentIds),
            'skipped_count' => count($skipped),
        ]);
        $list->touch();

        $this->directory->prime($me, collect($skipped)->map(fn ($m) => [$m->participant_type, $m->participant_id]));

        return [
            'sent' => count($sentIds),
            'skipped' => collect($skipped)->map(fn ($m) => $this->directory->profile($me, $m->participant_type, $m->participant_id))->filter()->values()->all(),
            'broadcast' => $this->presentSent($row, $first),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Model $me, ChatBroadcastList $list, bool $full = false): array
    {
        $members = $list->members()->get();
        $this->directory->prime($me, $members->map(fn ($m) => [$m->participant_type, $m->participant_id]));
        $profiles = $members->map(fn ($m) => $this->directory->profile($me, $m->participant_type, $m->participant_id))->filter()->values();
        $last = $list->sent()->latest('id')->first();

        $row = [
            'id' => $list->uuid,
            'name' => $list->name,
            // No name: the first few people, like WhatsApp ("Ahmed, Sara, +3").
            'title' => $list->name ?: $this->autoTitle($profiles->pluck('name')->filter()->all()),
            'members_count' => $members->count(),
            'last_sent' => $last ? ['body' => $last->body, 'type' => $last->type, 'at' => $last->created_at?->toIso8601String()] : null,
            'updated_at' => $list->updated_at?->toIso8601String(),
        ];

        if ($full) {
            $row['members'] = $profiles->all();
            $sent = $list->sent()->latest('id')->limit(50)->get()->reverse()->values();
            $firsts = ChatMessage::query()->whereIn('uuid', $sent->map(fn ($s) => $s->message_ids[0] ?? null)->filter())->with('media')->get()->keyBy('uuid');
            $row['sent'] = $sent->map(fn ($s) => $this->presentSent($s, $firsts->get($s->message_ids[0] ?? '')))->all();
        }

        return $row;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param  list<int>  $ids
     */
    private function syncMembers(Model $me, ChatBroadcastList $list, array $ids): void
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => ParticipantType::aliasFor($me) === 'user' && $id === (int) $me->getKey())->values();

        if ($ids->count() > self::MAX_MEMBERS) {
            throw new ChatException('broadcast_too_many', 422, ['max' => self::MAX_MEMBERS]);
        }

        $existing = User::query()->whereIn('id', $ids)->pluck('id');
        $list->members()->whereNotIn('participant_id', $existing)->delete();
        foreach ($existing as $id) {
            $list->members()->firstOrCreate(['participant_type' => 'user', 'participant_id' => $id]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSent(ChatBroadcastMessage $row, ?ChatMessage $first): array
    {
        $media = $first?->getFirstMedia(ChatMessage::ATTACHMENTS);

        return [
            'id' => $row->id,
            'type' => $row->type,
            'body' => $row->body,
            'thumbnail' => $media !== null && str_starts_with((string) $media->mime_type, 'image/') ? $media->getUrl() : null,
            'sent_count' => $row->sent_count,
            'skipped_count' => $row->skipped_count,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $names
     */
    private function autoTitle(array $names): string
    {
        $shown = array_slice($names, 0, 3);
        $more = count($names) - count($shown);

        return implode('، ', $shown).($more > 0 ? " +{$more}" : '');
    }

    private function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : mb_substr($name, 0, 100);
    }

    private function assertMine(Model $me, ChatBroadcastList $list): void
    {
        if (! $list->isOwnedBy($me)) {
            throw new ChatException('broadcast_not_found', 404);
        }
    }
}
