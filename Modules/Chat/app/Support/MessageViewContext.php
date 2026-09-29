<?php

namespace Modules\Chat\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMessageUserState;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatPollVote;

/**
 * Everything MessageResource needs to render messages for *one* viewer, loaded once per page:
 * the ticks (sent / delivered / read) of the viewer's own messages, what they starred, and the
 * people involved. Keeps a page of 50 messages to a fixed handful of queries.
 */
class MessageViewContext
{
    public int $deliveredUpTo = 0;

    public int $readUpTo = 0;

    public bool $showReads = true;

    /** @var array<int, true> */
    public array $starred = [];

    /** @var array<int, true> view-once messages somebody has already opened, by message row id */
    public array $opened = [];

    /** @var array<int, list<int>> the option ids I ticked, by message row id */
    public array $myVotes = [];

    /** @var array<int, string> participant row id => account key */
    public array $participantKeys = [];

    public function __construct(
        public readonly Model $viewer,
        public readonly ChatParticipant $me,
        public readonly ChatConversation $conversation,
        public readonly ParticipantDirectory $directory,
    ) {}

    /**
     * @param  Collection<int, ChatMessage>  $messages
     */
    public static function build(Model $viewer, ChatParticipant $me, ChatConversation $conversation, Collection $messages): self
    {
        $context = new self($viewer, $me, $conversation, app(ParticipantDirectory::class));

        $participants = $conversation->relationLoaded('participants') ? $conversation->participants : $conversation->participants()->get();
        $others = $participants->where('id', '!=', $me->id)->whereNull('left_at');

        foreach ($participants as $p) {
            $context->participantKeys[$p->id] = $p->key();
        }

        $context->deliveredUpTo = (int) ($others->min('last_delivered_message_id') ?? 0);
        $context->readUpTo = (int) ($others->min('last_read_message_id') ?? 0);

        $ids = $messages->pluck('id')->merge($messages->pluck('reply_to_id'))->filter()->unique()->all();

        if ($ids !== []) {
            $states = ChatMessageUserState::query()
                ->where('participant_id', $me->id)->whereIn('message_id', $ids)
                ->get(['message_id', 'starred_at', 'opened_at']);

            foreach ($states as $state) {
                if ($state->starred_at !== null) {
                    $context->starred[$state->message_id] = true;
                }
            }

            // A view-once file is opened by the recipient, so "opened" is a fact about the message,
            // not about the viewer: the sender sees it too (only a starred tick is the viewer's own).
            foreach (ChatMessageUserState::query()
                ->whereIn('message_id', $ids)->whereNotNull('opened_at')
                ->distinct()
                ->pluck('message_id') as $openedId) {
                $context->opened[$openedId] = true;
            }

            foreach (ChatPollVote::query()
                ->whereIn('message_id', $ids)
                ->where('participant_id', $me->id)
                ->orderBy('option_id')
                ->get(['message_id', 'option_id'])
                ->groupBy('message_id') as $messageId => $votes) {
                $context->myVotes[$messageId] = $votes->pluck('option_id')->map(fn ($n) => (int) $n)->all();
            }
        }

        $keys = $participants->map(fn (ChatParticipant $p) => [$p->participant_type, $p->participant_id])->all();
        foreach ($messages as $m) {
            if ($m->sender_type !== null) {
                $keys[] = [$m->sender_type, $m->sender_id];
            }
            foreach ((array) data_get($m->meta, 'targets', []) as $target) {
                if (is_string($target) && str_contains($target, ':')) {
                    $keys[] = explode(':', $target, 2);
                }
            }
        }
        $context->directory->prime($viewer, $keys);

        // Read receipts are mutual in a direct chat: if either side turned them off, nobody sees blue ticks.
        // (Groups always show them, like WhatsApp.) Read from the primed directory — no query per row.
        if (! $conversation->isGroup()) {
            $other = $others->first();
            $context->showReads = ($context->directory->privacyOf(ParticipantType::key($viewer))?->read_receipts ?? true)
                && ($other === null || ($context->directory->privacyOf($other->key())?->read_receipts ?? true));
        }

        return $context;
    }

    public function isMine(ChatMessage $message): bool
    {
        return $message->isFrom($this->me->participant_type, (int) $this->me->participant_id);
    }

    /**
     * Ticks for the viewer's own messages; null for everybody else's.
     */
    public function statusOf(ChatMessage $message): ?string
    {
        if (! $this->isMine($message)) {
            return null;
        }

        if ($this->showReads && $this->readUpTo >= $message->id) {
            return 'read';
        }

        if ($this->deliveredUpTo >= $message->id) {
            return 'delivered';
        }

        return 'sent';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function profile(?string $type, int|string|null $id): ?array
    {
        return $type === null ? null : $this->directory->profile($this->viewer, $type, $id);
    }

    public function profileOfParticipant(int $participantId): ?array
    {
        $key = $this->participantKeys[$participantId] ?? null;

        if ($key === null) {
            return null;
        }

        [$type, $id] = explode(':', $key, 2);

        return $this->profile($type, $id);
    }
}
