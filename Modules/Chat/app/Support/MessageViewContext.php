<?php

namespace Modules\Chat\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMessageReceipt;
use Modules\Chat\Models\ChatMessageUserState;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatPollVote;
use Modules\Chat\Enums\MessageType;

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

    /** @var array<int, true> message ids I set aside to "read later" */
    public array $readLater = [];

    /** @var array<int, true> message ids waiting for my reply */
    public array $followUp = [];

    /** @var array<int, string> message id => when I asked to be reminded (ISO) */
    public array $reminders = [];

    /** @var array<int, string> participant row id => account key */
    public array $participantKeys = [];

    /** @var array<int, true> view-once messages *I* opened */
    public array $openedByMe = [];

    /** @var array<int, int> my view-once messages => how many others opened them */
    public array $openedByOthers = [];

    /** @var array<int, array{counts: array<int, int>, voters: int}> poll message id => totals */
    public array $polls = [];

    /** @var array<int, list<int>> poll message id => the options I ticked */
    public array $myVotes = [];

    /** @var array<int, int> channel post id => how many followers have seen it */
    public array $views = [];

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
                ->where(fn ($q) => $q->whereNotNull('starred_at')->orWhereNotNull('read_later_at')->orWhereNotNull('follow_up_at'))
                ->get(['message_id', 'starred_at', 'read_later_at', 'follow_up_at']);
            $context->starred = $states->whereNotNull('starred_at')->pluck('message_id')->flip()->map(fn () => true)->all();
            $context->readLater = $states->whereNotNull('read_later_at')->pluck('message_id')->flip()->map(fn () => true)->all();
            $context->followUp = $states->whereNotNull('follow_up_at')->pluck('message_id')->flip()->map(fn () => true)->all();
            $context->reminders = \Modules\Chat\Models\ChatMessageReminder::query()
                ->where('participant_id', $me->id)->whereIn('message_id', $ids)->whereNull('sent_at')
                ->get(['message_id', 'remind_at'])->mapWithKeys(fn ($r) => [$r->message_id => $r->remind_at->toIso8601String()])->all();
        }

        // Channel posts show views instead of ticks: read receipts per post (one grouped query).
        if ($conversation->isChannel() && $messages->isNotEmpty()) {
            $context->views = ChatMessageReceipt::query()
                ->whereIn('message_id', $messages->pluck('id'))->whereNotNull('read_at')
                ->selectRaw('message_id, count(*) as n')->groupBy('message_id')
                ->pluck('n', 'message_id')->map(fn ($n) => (int) $n)->all();
        }

        // View-once: who opened what (one query for the whole page).
        $viewOnce = $messages->where('view_once', true)->pluck('id')->all();
        if ($viewOnce !== []) {
            $opened = ChatMessageUserState::query()->whereIn('message_id', $viewOnce)->whereNotNull('opened_at')->get(['message_id', 'participant_id']);
            foreach ($opened as $row) {
                if ((int) $row->participant_id === (int) $me->id) {
                    $context->openedByMe[$row->message_id] = true;
                } else {
                    $context->openedByOthers[$row->message_id] = ($context->openedByOthers[$row->message_id] ?? 0) + 1;
                }
            }
        }

        // Polls: totals and my own ticks.
        $polls = $messages->filter(fn (ChatMessage $m) => $m->type === MessageType::Poll)->pluck('id')->all();
        if ($polls !== []) {
            $votes = ChatPollVote::query()->whereIn('message_id', $polls)->get(['message_id', 'participant_id', 'option_id']);
            foreach ($polls as $id) {
                $rows = $votes->where('message_id', $id);
                $context->polls[$id] = [
                    'counts' => $rows->countBy('option_id')->map(fn ($n) => (int) $n)->all(),
                    'voters' => $rows->pluck('participant_id')->unique()->count(),
                ];
                $context->myVotes[$id] = $rows->where('participant_id', $me->id)->pluck('option_id')->map(fn ($o) => (int) $o)->values()->all();
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
        // A channel post has views, not ticks.
        if (! $this->isMine($message) || $this->conversation->isChannel()) {
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
