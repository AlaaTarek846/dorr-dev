<?php

namespace Modules\Chat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatTask;
use Modules\Chat\Support\ParticipantType;

/**
 * My tasks (spec 38): a private to-do list. Tasks come by hand or from a message (the AI suggests,
 * I save); one with a due time notifies me once (`chat:task-reminders`).
 */
class TaskService
{
    public const MAX_OPEN = 500;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
    ) {}

    /**
     * Save one or more tasks (from a message's suggestions they come together).
     *
     * @param  list<array{text: string, due_at?: ?string}>  $items
     * @return list<ChatTask>
     */
    public function create(Model $me, array $items, ?string $messageUuid): array
    {
        if (ChatTask::query()->ownedBy($me)->whereNull('done_at')->count() + count($items) > self::MAX_OPEN) {
            throw new ChatException('task_limit', 422, ['max' => self::MAX_OPEN]);
        }

        $message = null;
        if ($messageUuid !== null) {
            $message = ChatMessage::query()->where('uuid', $messageUuid)->with('conversation')->first() ?? throw new ChatException('message_not_found', 404);
            $participant = $this->conversations->participantOf($me, $message->conversation);
            if (! $this->messages->visibleTo($participant, ChatMessage::query()->whereKey($message->id))->exists()) {
                throw new ChatException('message_not_found', 404);
            }
        }

        $order = (int) ChatTask::query()->ownedBy($me)->max('sort_order');

        return DB::transaction(fn () => collect($items)->map(fn (array $item) => ChatTask::query()->create([
            'uuid' => (string) Str::uuid(),
            'owner_type' => ParticipantType::aliasFor($me),
            'owner_id' => $me->getKey(),
            'text' => Str::limit(trim($item['text']), 300, ''),
            'due_at' => ! empty($item['due_at']) ? CarbonImmutable::parse($item['due_at'])->utc() : null,
            'message_id' => $message?->id,
            'conversation_id' => $message?->conversation_id,
            'sort_order' => ++$order,
        ]))->all());
    }

    /**
     * @param  array{text?: string, due_at?: ?string, done?: bool}  $data
     */
    public function update(Model $me, ChatTask $task, array $data): ChatTask
    {
        $this->assertMine($me, $task);
        $fill = [];
        if (array_key_exists('text', $data)) {
            $fill['text'] = Str::limit(trim((string) $data['text']), 300, '');
        }
        if (array_key_exists('due_at', $data)) {
            $fill['due_at'] = $data['due_at'] ? CarbonImmutable::parse($data['due_at'])->utc() : null;
            $fill['reminded_at'] = null; // a new time notifies again
        }
        if (array_key_exists('done', $data)) {
            $fill['done_at'] = $data['done'] ? ($task->done_at ?? now()) : null;
        }
        $task->update($fill);

        return $task->refresh();
    }

    public function assertMine(Model $me, ChatTask $task): void
    {
        if (! $task->isOwnedBy($me)) {
            throw new ChatException('task_not_found', 404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ChatTask $task): array
    {
        return [
            'id' => $task->uuid,
            'text' => $task->text,
            'due_at' => $task->due_at?->toIso8601String(),
            'done' => $task->done_at !== null,
            'done_at' => $task->done_at?->toIso8601String(),
            'message_id' => $task->message?->uuid,
            'conversation_id' => $task->conversation?->uuid,
            'created_at' => $task->created_at?->toIso8601String(),
        ];
    }
}
