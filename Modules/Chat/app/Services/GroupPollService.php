<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Support\GroupScope;

/**
 * Polls in educational and work groups (docs/groups_tasks.md GR-045): the chat's own poll, and an
 * anonymous one — its numbers for everyone, never who voted for what (not even for its maker, not
 * in the live event).
 */
class GroupPollService
{
    public function __construct(private readonly MessageService $messages) {}

    /**
     * @param  list<string>  $options
     * @return array{message_id: string}
     */
    public function create(Model $me, ChatConversation $conversation, string $question, array $options, bool $multiple, bool $anonymous): array
    {
        if (! GroupLifecycleService::isKindGroup($conversation)) {
            throw new ChatException('group_kind_required', 422);
        }
        GroupScope::member($me, $conversation->id) ?? throw ChatException::notParticipant();

        $message = $this->messages->send($me, $conversation, ['type' => 'poll', 'body' => trim($question), 'poll_options' => $options, 'poll_multiple' => $multiple]);
        if ($anonymous) {
            $message->update(['meta' => array_merge((array) $message->meta, ['anonymous' => true])]);
            $this->messages->rebroadcast($message->refresh());
        }

        return ['message_id' => $message->uuid];
    }
}
