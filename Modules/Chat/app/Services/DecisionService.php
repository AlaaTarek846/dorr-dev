<?php

namespace Modules\Chat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatDecisionArgument;
use Modules\Chat\Models\ChatGroupDecision;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Support\ParticipantDirectory;

/**
 * Group decisions (spec 119–120):
 *  - "Make it a decision" on any message of a group: a poll answering that message is posted
 *    (default options: agree / disagree), and the decision is open;
 *  - the admins approve it (with its outcome — by default the option with most votes) or reject it;
 *  - the decisions log lists them, approved first-class with their date.
 */
class DecisionService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ParticipantDirectory $directory,
    ) {}

    /**
     * @param  list<string>|null  $options
     */
    public function fromMessage(Model $me, ChatMessage $source, ?string $title, ?array $options, ?string $description = null, ?string $deadline = null): ChatGroupDecision
    {
        $conversation = $source->conversation;

        if (! $conversation->isGroup() || $conversation->isChannel()) {
            throw new ChatException('decision_groups_only', 422);
        }
        if ($source->isGone() || in_array($source->type, [MessageType::System, MessageType::Call, MessageType::Poll], true)) {
            throw new ChatException('decision_unavailable', 422);
        }

        $participant = $this->conversations->participantOf($me, $conversation, true);
        $title = trim((string) ($title ?: Str::limit((string) $source->body, 280))) ?: __('chat.decision.default_title');
        $options = array_values(array_filter(array_map('trim', $options ?? []), fn ($o) => $o !== '')) ?: [__('chat.decision.agree'), __('chat.decision.disagree')];

        if (ChatGroupDecision::query()->where('source_message_id', $source->id)->where('status', 'open')->exists()) {
            throw new ChatException('decision_already_open', 409);
        }

        return DB::transaction(function () use ($me, $source, $conversation, $participant, $title, $options, $description, $deadline) {
            $poll = $this->messages->send($me, $conversation, [
                'type' => MessageType::Poll->value,
                'body' => $title,
                'poll_options' => $options,
                'reply_to' => $source->uuid,
            ]);

            $decision = ChatGroupDecision::query()->create([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'source_message_id' => $source->id,
                'poll_message_id' => $poll->id,
                'title' => mb_substr($title, 0, 300),
                'description' => $description !== null && trim($description) !== '' ? mb_substr(trim($description), 0, 1000) : null,
                'deadline_at' => $deadline ? CarbonImmutable::parse($deadline)->utc() : null,
                'status' => 'open',
                'created_by_participant_id' => $participant->id,
            ]);

            // The vote knows it decides something (the app shows a "Decision" badge on it).
            $poll->update(['meta' => array_merge($poll->meta ?? [], ['decision' => $decision->uuid])]);

            return $decision;
        });
    }

    /** Admins: approve (with the outcome; the most voted option by default) or reject. */
    public function decide(Model $me, ChatGroupDecision $decision, bool $approve, ?string $outcome): ChatGroupDecision
    {
        $participant = $this->conversations->participantOf($me, $decision->conversation, true);

        if (! $participant->isAdmin()) {
            throw ChatException::adminsOnly();
        }
        if ($decision->status !== 'open') {
            throw new ChatException('decision_closed', 409);
        }

        $outcome = trim((string) $outcome) ?: ($approve ? $this->leadingOption($decision) : null);

        $decision->update([
            'status' => $approve ? 'approved' : 'rejected',
            'outcome' => $outcome !== null ? mb_substr($outcome, 0, 300) : null,
            'decided_by_participant_id' => $participant->id,
            'decided_at' => now(),
        ]);

        // A line in the chat, so everyone learns it was decided.
        $this->messages->system($decision->conversation, $me, $approve ? 'decision_approved' : 'decision_rejected', [], ['name' => Str::limit($decision->title, 80)]);

        return $decision->refresh();
    }

    /**
     * The group's decisions log: approved ones (newest decided first), then the open ones; rejected
     * only when asked.
     *
     * @return list<array<string, mixed>>
     */
    public function log(Model $me, ChatConversation $conversation, ?string $status): array
    {
        $this->conversations->participantOf($me, $conversation);

        $rows = ChatGroupDecision::query()->where('conversation_id', $conversation->id)
            ->when($status, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->whereIn('status', ['approved', 'open']))
            ->with(['poll', 'source', 'createdBy', 'decidedBy'])
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 WHEN 'open' THEN 1 ELSE 2 END")
            ->orderByDesc('decided_at')->orderByDesc('id')
            ->limit(200)->get();

        $this->directory->prime($me, $rows->flatMap(fn ($d) => array_filter([
            $d->createdBy ? [$d->createdBy->participant_type, $d->createdBy->participant_id] : null,
            $d->decidedBy ? [$d->decidedBy->participant_type, $d->decidedBy->participant_id] : null,
        ])));

        return $rows->map(fn (ChatGroupDecision $d) => $this->present($me, $d))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Model $me, ChatGroupDecision $d): array
    {
        $summary = $d->poll ? MessageExtrasService::pollSummary($d->poll->id) : ['counts' => [], 'voters' => 0];
        $person = fn ($p) => $p ? $this->directory->profile($me, $p->participant_type, $p->participant_id) : null;

        return [
            'id' => $d->uuid,
            'title' => $d->title,
            'status' => $d->status,
            'outcome' => $d->outcome,
            'source_message_id' => $d->source?->uuid,
            'poll_message_id' => $d->poll?->uuid,
            'options' => collect($d->poll?->meta['options'] ?? [])->map(fn ($o) => ['id' => $o['id'], 'text' => $o['text'], 'votes' => (int) ($summary['counts'][$o['id']] ?? 0)])->values()->all(),
            'voters' => $summary['voters'],
            'created_by' => $person($d->createdBy),
            'decided_by' => $person($d->decidedBy),
            'created_at' => $d->created_at?->toIso8601String(),
            'decided_at' => $d->decided_at?->toIso8601String(),
            // The decision room (spec 153).
            'description' => $d->description,
            'deadline_at' => $d->deadline_at?->toIso8601String(),
            'is_closed' => $d->status !== 'open' || ($d->deadline_at !== null && $d->deadline_at->isPast()),
            'arguments_count' => $d->arguments_count ?? $d->arguments()->count(),
        ];
    }

    /**
     * The room: the decision with everyone's arguments (for / against / a note, on an option or
     * the whole question), newest last.
     *
     * @return array<string, mixed>
     */
    public function room(Model $me, ChatGroupDecision $d): array
    {
        $mine = $this->conversations->participantOf($me, $d->conversation);
        $d->loadMissing(['poll', 'source', 'createdBy', 'decidedBy', 'arguments.participant']);
        $this->directory->prime($me, $d->arguments->map(fn ($a) => [$a->participant?->participant_type, $a->participant?->participant_id]));

        return $this->present($me, $d) + [
            'can_decide' => $mine->isAdmin() && $d->status === 'open',
            'arguments' => $d->arguments->sortBy('id')->map(fn (ChatDecisionArgument $a) => [
                'id' => $a->id,
                'stance' => $a->stance,
                'option_id' => $a->option_id,
                'text' => $a->text,
                'by' => $a->participant ? $this->directory->profile($me, $a->participant->participant_type, $a->participant->participant_id) : null,
                'is_mine' => $a->participant_id === $mine->id,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** One member's argument (members only, while the decision is open and before its deadline). */
    public function argue(Model $me, ChatGroupDecision $d, string $stance, string $text, ?string $optionId): void
    {
        $participant = $this->conversations->participantOf($me, $d->conversation, true);
        if ($d->status !== 'open' || ($d->deadline_at !== null && $d->deadline_at->isPast())) {
            throw new ChatException('decision_closed', 409);
        }
        if ($d->arguments()->where('participant_id', $participant->id)->count() >= 20) {
            throw new ChatException('decision_too_many_arguments', 422);
        }
        $options = collect($d->poll?->meta['options'] ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $d->arguments()->create([
            'participant_id' => $participant->id,
            'option_id' => $optionId !== null && in_array($optionId, $options, true) ? $optionId : null,
            'stance' => $stance,
            'text' => mb_substr(trim($text), 0, 500),
        ]);
    }

    /** Mine — or anyone's, for a group admin. */
    public function removeArgument(Model $me, ChatGroupDecision $d, int $argumentId): void
    {
        $participant = $this->conversations->participantOf($me, $d->conversation);
        $argument = $d->arguments()->find($argumentId) ?? throw new ChatException('message_not_found', 404);
        if ($argument->participant_id !== $participant->id && ! $participant->isAdmin()) {
            throw ChatException::adminsOnly();
        }
        $argument->delete();
    }

    private function leadingOption(ChatGroupDecision $decision): ?string
    {
        $poll = $decision->poll;
        if ($poll === null) {
            return null;
        }
        $counts = MessageExtrasService::pollSummary($poll->id)['counts'];
        arsort($counts);
        $top = array_key_first($counts);

        return $top === null ? null : collect($poll->meta['options'] ?? [])->firstWhere('id', $top)['text'] ?? null;
    }
}
