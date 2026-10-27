<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * My chats' storage, like WhatsApp's "Manage storage" (backlog 46–48): how much each chat's
 * files take, the biggest files and the ones forwarded many times across every chat, and
 * deleting a batch of them for me (nobody else loses anything).
 */
class ChatStorageService
{
    /** A "large" file. */
    public const LARGE_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly MessageService $messages,
        private readonly ConversationService $conversations,
    ) {}

    /**
     * @return array{total_bytes: int, files: int, large: int, forwarded: int, chats: list<array<string, mixed>>}
     */
    public function overview(Model $me): array
    {
        $rows = $this->files($me)
            ->groupBy('m.conversation_id')
            ->selectRaw('m.conversation_id, sum(md.size) as bytes, count(distinct m.id) as messages')
            ->orderByDesc('bytes')
            ->limit(100)
            ->get();
        $totals = $this->files($me)->selectRaw('coalesce(sum(md.size), 0) as bytes, count(distinct m.id) as messages')->first();
        $large = $this->perMessage($me)->having('bytes', '>=', self::LARGE_BYTES)->get()->count();
        $forwarded = (clone $this->files($me))->where('m.is_forwarded', true)->distinct()->count('m.id');

        $conversations = ChatConversation::query()->with('group')->whereIn('id', $rows->pluck('conversation_id'))->get()->keyBy('id');

        return [
            'total_bytes' => (int) ($totals->bytes ?? 0),
            'files' => (int) ($totals->messages ?? 0),
            'large' => $large,
            'forwarded' => $forwarded,
            'chats' => $rows->map(function ($row) use ($conversations, $me) {
                $conversation = $conversations->get($row->conversation_id);

                return $conversation ? [
                    'conversation_id' => $conversation->uuid,
                    'title' => $this->titleOf($me, $conversation),
                    'type' => $conversation->type->value,
                    'bytes' => (int) $row->bytes,
                    'files' => (int) $row->messages,
                ] : null;
            })->filter()->values()->all(),
        ];
    }

    /**
     * The messages with files, biggest first: `kind` all · large · forwarded, optionally in one chat.
     *
     * @return array{items: list<array<string, mixed>>, has_more: bool}
     */
    public function list(Model $me, string $kind, ?string $conversationUuid, int $page, int $perPage = 15): array
    {
        $query = $this->perMessage($me)
            ->when($conversationUuid, fn (Builder $q) => $q->where('m.conversation_id', ChatConversation::query()->where('uuid', $conversationUuid)->value('id') ?? 0))
            ->when($kind === 'forwarded', fn (Builder $q) => $q->where('m.is_forwarded', true))
            ->when($kind === 'large', fn (Builder $q) => $q->having('bytes', '>=', self::LARGE_BYTES))
            ->orderByDesc('bytes')->orderByDesc('m.id');
        $per = max(1, min($perPage, 50));
        $rows = $query->offset(($page - 1) * $per)->limit($per + 1)->get();
        $messages = ChatMessage::query()->with(['media', 'conversation.group'])->whereIn('id', $rows->take($per)->pluck('id'))->get()->keyBy('id');

        return [
            'items' => $rows->take($per)->map(function ($row) use ($messages, $me) {
                $message = $messages->get($row->id);
                $first = $message?->getMedia(ChatMessage::ATTACHMENTS)->sortBy('order_column')->first();
                $poster = $message?->getFirstMedia(ChatMessage::THUMBNAIL);

                $conversation = $message?->conversation;
                return $message && $conversation ? [
                    'message_id' => $message->uuid,
                    'conversation_id' => $conversation->uuid,
                    'chat' => $this->titleOf($me, $message->conversation),
                    'type' => $message->type->value,
                    'bytes' => (int) $row->bytes,
                    'files' => (int) $row->files,
                    'name' => $first?->file_name,
                    'mime_type' => $first?->mime_type,
                    'thumbnail' => $poster?->getUrl() ?? (str_starts_with((string) $first?->mime_type, 'image/') ? $first?->getUrl() : null),
                    'forwarded' => (bool) $message->is_forwarded,
                    'created_at' => $message->created_at?->toIso8601String(),
                ] : null;
            })->filter()->values()->all(),
            'has_more' => $rows->count() > $per,
        ];
    }

    /**
     * Delete these messages for me, wherever they are.
     *
     * @param  list<string>  $uuids
     */
    public function delete(Model $me, array $uuids): int
    {
        $deleted = 0;
        ChatMessage::query()->whereIn('uuid', $uuids)->get(['id', 'uuid', 'conversation_id'])->groupBy('conversation_id')
            ->each(function ($messages, $conversationId) use ($me, &$deleted) {
                $conversation = ChatConversation::query()->find($conversationId);
                if ($conversation !== null) {
                    $deleted += $this->messages->deleteForMe($me, $conversation, $messages->pluck('uuid')->all());
                }
            });

        return $deleted;
    }

    /** Every file of a message I can still see, one row per file. */
    private function files(Model $me): Builder
    {
        return DB::table('chat_messages as m')
            ->join('chat_participants as p', function ($join) use ($me) {
                $join->on('p.conversation_id', '=', 'm.conversation_id')
                    ->where('p.participant_type', ParticipantType::aliasFor($me))
                    ->where('p.participant_id', $me->getKey());
            })
            ->join('media as md', function ($join) {
                $join->on('md.model_id', '=', 'm.id')
                    ->where('md.model_type', (new ChatMessage)->getMorphClass())
                    ->where('md.collection_name', ChatMessage::ATTACHMENTS);
            })
            ->whereNull('m.deleted_for_everyone_at')
            ->where('m.view_once', false)
            ->where(fn (Builder $q) => $q->whereNull('p.cleared_before_message_id')->orWhereColumn('m.id', '>', 'p.cleared_before_message_id'))
            ->where(fn (Builder $q) => $q->whereNull('p.left_at')->orWhereColumn('m.created_at', '<=', 'p.left_at'))
            ->where(fn (Builder $q) => $q->whereNull('m.expires_at')->orWhere('m.expires_at', '>', now()))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('chat_message_user_states as s')
                ->whereColumn('s.message_id', 'm.id')->whereColumn('s.participant_id', 'p.id')->whereNotNull('s.deleted_at'));
    }

    /** One row per message: its files' total size. */
    private function perMessage(Model $me): Builder
    {
        return $this->files($me)->groupBy('m.id')->selectRaw('m.id, sum(md.size) as bytes, count(md.id) as files');
    }

    private function titleOf(Model $me, ChatConversation $conversation): ?string
    {
        if ($conversation->group !== null) {
            return $conversation->group->name;
        }
        $peer = $conversation->participants()->whereNot(fn ($q) => $q->where('participant_type', ParticipantType::aliasFor($me))->where('participant_id', $me->getKey()))->first();

        return $peer ? (app(ParticipantDirectory::class)->profile($me, $peer->participant_type, $peer->participant_id)['name'] ?? null) : null;
    }
}
