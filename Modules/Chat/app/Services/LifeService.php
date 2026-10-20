<?php

namespace Modules\Chat\Services;

use App\Support\LocaleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatAgreement;
use Modules\Chat\Models\ChatCommitment;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatHandoff;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\ParticipantDirectory;

/**
 * Daily intelligence, batch A (spec 148, 149, 152, 154):
 *  - commitments: "who will do what, by when" — proposed on a message or in a chat, the one
 *    responsible accepts or declines; done or cancelled later; an optional reminder before;
 *  - handoffs: a message handed to someone allowed (a member of the chat, or — forwarded under
 *    the usual rules — someone I can message), who takes it on;
 *  - agreements: the terms the parties approve, version by version; a change starts over.
 * Nothing operational happens before the person confirms and the server checks who may
 * (AT-LIFE-01), and an agreement never pays, books or charges anything — it's a record. Each one
 * has its card in the chat (its meta is the live snapshot, re-sent on every change) and a log.
 */
class LifeService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ParticipantDirectory $directory,
        private readonly ChatPushNotifier $push,
    ) {}

    // ------------------------------------------------------------------ commitments (149)

    /** @param  array{title?: ?string, assignee?: ?int, due_at?: ?string, remind_minutes?: ?int}  $data */
    public function commit(Model $me, ChatConversation $conversation, ?ChatMessage $source, array $data): ChatCommitment
    {
        $mine = $this->conversations->participantOf($me, $conversation, true);
        $this->assertUsable($conversation, $source);
        $assignee = $this->member($conversation, $data['assignee'] ?? null) ?? ($conversation->isGroup() ? $mine : $this->other($conversation, $mine) ?? $mine);
        $title = $this->titleFrom($data['title'] ?? null, $source);
        $due = ! empty($data['due_at']) ? CarbonImmutable::parse($data['due_at'])->utc() : null;
        // Taking it on myself is accepting it.
        $self = $assignee->id === $mine->id;

        return DB::transaction(function () use ($me, $conversation, $source, $mine, $assignee, $title, $due, $data, $self) {
            $c = ChatCommitment::query()->create([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'source_message_id' => $source?->id,
                'created_by_participant_id' => $mine->id,
                'assignee_participant_id' => $assignee->id,
                'title' => $title,
                'due_at' => $due,
                'remind_minutes' => $due !== null && isset($data['remind_minutes']) ? (int) $data['remind_minutes'] : null,
                'status' => $self ? 'accepted' : 'proposed',
                'responded_at' => $self ? now() : null,
            ]);
            $this->log('commitment', $c->id, $mine, 'created', ['assignee' => $assignee->id, 'due_at' => $due?->toIso8601String()]);
            $card = $this->messages->send($me, $conversation, ['type' => MessageType::Commitment->value, 'body' => $title, 'reply_to' => $source?->uuid, 'meta_raw' => $this->commitmentMeta($me, $c)]);
            $c->forceFill(['card_message_id' => $card->id])->save();

            return $c;
        });
    }

    /** The one responsible answers. */
    public function respondCommitment(Model $me, ChatCommitment $c, bool $accept): ChatCommitment
    {
        $mine = $this->conversations->participantOf($me, $c->conversation, true);
        if ($mine->id !== $c->assignee_participant_id) {
            throw new ChatException('life_not_yours', 403);
        }
        if ($c->status !== 'proposed') {
            throw new ChatException('life_closed', 409);
        }
        $c->update(['status' => $accept ? 'accepted' : 'declined', 'responded_at' => now()]);
        $this->log('commitment', $c->id, $mine, $accept ? 'accepted' : 'declined');
        $this->refreshCard($me, $c->card, $this->commitmentMeta($me, $c));
        $this->tell($c->createdBy, $accept ? 'commitment_accepted' : 'commitment_declined', $me, $c->title, $c->conversation, $c->card);

        return $c->refresh();
    }

    /** Done (the one responsible) or cancelled (the one who asked, or the one responsible before accepting). */
    public function closeCommitment(Model $me, ChatCommitment $c, string $to): ChatCommitment
    {
        $mine = $this->conversations->participantOf($me, $c->conversation, true);
        if (! in_array($c->status, ChatCommitment::OPEN, true)) {
            throw new ChatException('life_closed', 409);
        }
        $allowed = $to === 'done' ? $mine->id === $c->assignee_participant_id && $c->status === 'accepted' : $mine->id === $c->created_by_participant_id || $mine->id === $c->assignee_participant_id;
        if (! $allowed) {
            throw new ChatException('life_not_yours', 403);
        }
        $c->update(['status' => $to, 'done_at' => $to === 'done' ? now() : null]);
        $this->log('commitment', $c->id, $mine, $to);
        $this->refreshCard($me, $c->card, $this->commitmentMeta($me, $c));
        $other = $mine->id === $c->created_by_participant_id ? $c->assignee : $c->createdBy;
        if ($other !== null && $other->id !== $mine->id) {
            $this->tell($other, $to === 'done' ? 'commitment_done' : 'commitment_cancelled', $me, $c->title, $c->conversation, $c->card);
        }

        return $c->refresh();
    }

    /**
     * My commitments: on me (`mine`), the ones I asked others for (`theirs`).
     *
     * @return list<array<string, mixed>>
     */
    public function commitments(Model $me, string $scope, bool $open): array
    {
        $ids = ChatParticipant::query()->of($me)->pluck('id');
        $rows = ChatCommitment::query()->with(['conversation.group', 'source', 'createdBy', 'assignee'])
            ->when($scope === 'mine', fn ($q) => $q->whereIn('assignee_participant_id', $ids))
            ->when($scope === 'theirs', fn ($q) => $q->whereIn('created_by_participant_id', $ids)->whereNotIn('assignee_participant_id', $ids))
            ->when($scope === 'all', fn ($q) => $q->where(fn ($w) => $w->whereIn('assignee_participant_id', $ids)->orWhereIn('created_by_participant_id', $ids)))
            ->when($open, fn ($q) => $q->whereIn('status', ChatCommitment::OPEN))
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->orderByDesc('id')->limit(200)->get();

        return $rows->map(fn ($c) => $this->commitmentMeta($me, $c) + ['conversation' => $this->conversationRef($c->conversation)])->values()->all();
    }

    /** Accepted ones whose reminder time came (the reminders command). */
    public function remindDue(): int
    {
        $sent = 0;
        ChatCommitment::query()->where('status', 'accepted')->whereNotNull('due_at')->whereNotNull('remind_minutes')->whereNull('reminded_at')
            ->where('due_at', '<=', now()->addDay())->with(['assignee', 'conversation'])->orderBy('id')
            ->each(function (ChatCommitment $c) use (&$sent) {
                if ($c->due_at->copy()->subMinutes((int) $c->remind_minutes)->isFuture() || $c->assignee === null) {
                    return;
                }
                // Claimed first: never twice (two schedulers, a retry).
                if (ChatCommitment::query()->whereKey($c->id)->whereNull('reminded_at')->update(['reminded_at' => now()]) === 0) {
                    return;
                }
                $this->push->toAccounts([[$c->assignee->participant_type, (int) $c->assignee->participant_id]], $this->langs('chat.push.commitment_reminder_title'), ['en' => $c->title],
                    ['type' => 'chat', 'event' => 'chat.commitment.reminder', 'conversation_uuid' => $c->conversation->uuid, 'commitment_id' => $c->uuid]);
                $sent++;
            });

        return $sent;
    }

    // ------------------------------------------------------------------ handoffs (152)

    /**
     * Hand a message on: to a member of this chat (the card here), or to someone I can message
     * (the message is forwarded to our chat under the usual rules, the card answers it there).
     */
    public function handOff(Model $me, ChatMessage $source, ?int $toParticipantId, ?Model $toAccount, ?string $note): ChatHandoff
    {
        $conversation = $source->conversation;
        $mine = $this->conversations->participantOf($me, $conversation, true);
        $this->assertUsable($conversation, $source);

        return DB::transaction(function () use ($me, $source, $conversation, $mine, $toParticipantId, $toAccount, $note) {
            if ($toParticipantId !== null) {
                $to = $this->member($conversation, $toParticipantId) ?? throw new ChatException('life_not_member', 422);
                $where = $conversation;
                $from = $mine;
                $replyTo = $source;
            } else {
                if ($toAccount === null) {
                    throw new ChatException('life_not_member', 422);
                }
                $from = $this->conversations->openDirect($me, $toAccount);
                $where = $from->conversation;
                $to = $this->other($where, $from) ?? throw new ChatException('life_not_member', 422);
                $replyTo = $this->messages->forward($me, [$source->uuid], [$where->uuid])[0];
            }
            if ($to->id === $from->id) {
                throw new ChatException('life_not_yourself', 422);
            }
            $h = ChatHandoff::query()->create([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $where->id,
                'source_message_id' => $source->id,
                'from_participant_id' => $from->id,
                'to_participant_id' => $to->id,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
                'status' => 'pending',
            ]);
            $this->log('handoff', $h->id, $from, 'created', ['to' => $to->id, 'from_conversation' => $conversation->uuid]);
            $card = $this->messages->send($me, $where, ['type' => MessageType::Handoff->value, 'body' => $h->note ?? Str::limit((string) $source->body, 200), 'reply_to' => $replyTo->uuid, 'meta_raw' => $this->handoffMeta($me, $h)]);
            $h->forceFill(['card_message_id' => $card->id])->save();

            return $h;
        });
    }

    /** accept · decline (the one it was handed to), done (them, after accepting), cancel (the sender, while pending). */
    public function moveHandoff(Model $me, ChatHandoff $h, string $to): ChatHandoff
    {
        $mine = $this->conversations->participantOf($me, $h->conversation, true);
        $receiver = $mine->id === $h->to_participant_id;
        $ok = match ($to) {
            'accepted', 'declined' => $receiver && $h->status === 'pending',
            'done' => $receiver && $h->status === 'accepted',
            'cancelled' => $mine->id === $h->from_participant_id && $h->status === 'pending',
            default => false,
        };
        if (! $ok) {
            throw new ChatException(in_array($h->status, ChatHandoff::OPEN, true) ? 'life_not_yours' : 'life_closed', in_array($h->status, ChatHandoff::OPEN, true) ? 403 : 409);
        }
        $h->update(['status' => $to, 'responded_at' => in_array($to, ['accepted', 'declined'], true) ? now() : $h->responded_at, 'done_at' => $to === 'done' ? now() : null]);
        $this->log('handoff', $h->id, $mine, $to);
        $this->refreshCard($me, $h->card, $this->handoffMeta($me, $h));
        $other = $receiver ? $h->from : $h->to;
        if ($other !== null) {
            $this->tell($other, 'handoff_'.$to, $me, Str::limit((string) ($h->note ?? $h->source?->body ?? ''), 80), $h->conversation, $h->card);
        }

        return $h->refresh();
    }

    /** @return list<array<string, mixed>> handed to me (`received`) or by me (`sent`) */
    public function handoffs(Model $me, string $scope, bool $open): array
    {
        $ids = ChatParticipant::query()->of($me)->pluck('id');

        return ChatHandoff::query()->with(['conversation.group', 'source', 'from', 'to'])
            ->whereIn($scope === 'sent' ? 'from_participant_id' : 'to_participant_id', $ids)
            ->when($open, fn ($q) => $q->whereIn('status', ChatHandoff::OPEN))
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn ($h) => $this->handoffMeta($me, $h) + ['conversation' => $this->conversationRef($h->conversation)])->values()->all();
    }

    // ------------------------------------------------------------------ agreements (154)

    /**
     * @param  list<array{label: string, value: string}>  $terms
     * @param  list<int>|null  $parties  participant ids (a group: who must agree; me included)
     */
    public function propose(Model $me, ChatConversation $conversation, ?ChatMessage $source, string $title, array $terms, ?array $parties): ChatAgreement
    {
        $mine = $this->conversations->participantOf($me, $conversation, true);
        $this->assertUsable($conversation, $source);
        $ids = $this->parties($conversation, $mine, $parties);
        $terms = $this->cleanTerms($terms);

        return DB::transaction(function () use ($me, $conversation, $source, $mine, $title, $terms, $ids) {
            $a = ChatAgreement::query()->create([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'source_message_id' => $source?->id,
                'created_by_participant_id' => $mine->id,
                'title' => mb_substr(trim($title), 0, 200),
                'terms' => $terms,
                'parties' => $ids,
                // Proposing it is agreeing to it.
                'approvals' => [(string) $mine->id => ['decision' => 'accepted', 'at' => now()->toIso8601String()]],
                'version' => 1,
                'status' => 'proposed',
            ]);
            $this->log('agreement', $a->id, $mine, 'proposed', ['version' => 1, 'terms' => $terms]);
            $card = $this->messages->send($me, $conversation, ['type' => MessageType::Agreement->value, 'body' => $a->title, 'reply_to' => $source?->uuid, 'meta_raw' => $this->agreementMeta($me, $a)]);
            $a->forceFill(['card_message_id' => $card->id])->save();

            return $a;
        });
    }

    /** A party approves or declines this version (an older version's answer is refused). */
    public function answer(Model $me, ChatAgreement $a, bool $accept, int $version): ChatAgreement
    {
        $mine = $this->party($me, $a);
        if ($a->status !== 'proposed') {
            throw new ChatException('life_closed', 409);
        }
        if ($version !== $a->version) {
            throw new ChatException('agreement_changed', 409, [], ['version' => $a->version]);
        }

        return DB::transaction(function () use ($me, $a, $mine, $accept) {
            $approvals = (array) $a->approvals;
            $approvals[(string) $mine->id] = ['decision' => $accept ? 'accepted' : 'declined', 'at' => now()->toIso8601String()];
            $everyone = collect($a->partyIds())->every(fn ($id) => ($approvals[(string) $id]['decision'] ?? null) === 'accepted');
            $a->update(['approvals' => $approvals, 'status' => ! $accept ? 'declined' : ($everyone ? 'agreed' : 'proposed'), 'agreed_at' => $everyone && $accept ? now() : null]);
            $this->log('agreement', $a->id, $mine, $accept ? 'accepted' : 'declined', ['version' => $a->version]);
            $this->refreshCard($me, $a->card, $this->agreementMeta($me, $a));
            if ($a->status === 'agreed') {
                // Everyone learns it's agreed, in the chat itself.
                $this->messages->system($a->conversation, $me, 'agreement_agreed', [], ['name' => Str::limit($a->title, 80)]);
            } elseif (! $accept) {
                $this->tell($a->createdBy, 'agreement_declined', $me, $a->title, $a->conversation, $a->card);
            }

            return $a->refresh();
        });
    }

    /**
     * A party changes the terms: a new version, every approval starts over (the one who changed
     * it agrees to it).
     *
     * @param  list<array{label: string, value: string}>  $terms
     */
    public function amend(Model $me, ChatAgreement $a, ?string $title, array $terms): ChatAgreement
    {
        $mine = $this->party($me, $a);
        if (! in_array($a->status, ['proposed', 'declined'], true)) {
            throw new ChatException('life_closed', 409);
        }
        $terms = $this->cleanTerms($terms);

        return DB::transaction(function () use ($me, $a, $mine, $title, $terms) {
            $before = ['title' => $a->title, 'terms' => $a->terms];
            $a->update([
                'title' => $title !== null && trim($title) !== '' ? mb_substr(trim($title), 0, 200) : $a->title,
                'terms' => $terms,
                'version' => $a->version + 1,
                'status' => 'proposed',
                'approvals' => [(string) $mine->id => ['decision' => 'accepted', 'at' => now()->toIso8601String()]],
            ]);
            $this->log('agreement', $a->id, $mine, 'amended', ['version' => $a->version, 'before' => $before, 'terms' => $terms]);
            $this->refreshCard($me, $a->card, $this->agreementMeta($me, $a));

            return $a->refresh();
        });
    }

    public function cancelAgreement(Model $me, ChatAgreement $a): ChatAgreement
    {
        $mine = $this->conversations->participantOf($me, $a->conversation, true);
        if ($mine->id !== $a->created_by_participant_id) {
            throw new ChatException('life_not_yours', 403);
        }
        if ($a->status === 'cancelled') {
            throw new ChatException('life_closed', 409);
        }
        $a->update(['status' => 'cancelled']);
        $this->log('agreement', $a->id, $mine, 'cancelled');
        $this->refreshCard($me, $a->card, $this->agreementMeta($me, $a));

        return $a->refresh();
    }

    /** @return array<string, mixed> the card plus its full history */
    public function agreement(Model $me, ChatAgreement $a): array
    {
        $this->conversations->participantOf($me, $a->conversation);

        return $this->agreementMeta($me, $a) + ['log' => $this->history('agreement', $a->id, $me)];
    }

    /** @return list<array<string, mixed>> the history of a commitment, a handoff or an agreement */
    public function history(string $type, int $id, Model $me): array
    {
        return DB::table('chat_life_log')->where('subject_type', $type)->where('subject_id', $id)->orderBy('id')->get()
            ->map(function ($row) use ($me) {
                $actor = $row->actor_participant_id ? ChatParticipant::query()->find($row->actor_participant_id) : null;

                return ['action' => $row->action, 'by' => $actor ? $this->person($me, $actor) : null, 'data' => json_decode((string) $row->data, true), 'at' => CarbonImmutable::parse($row->created_at)->toIso8601String()];
            })->values()->all();
    }

    // ------------------------------------------------------------------ the cards

    /** @return array<string, mixed> */
    public function commitmentMeta(Model $me, ChatCommitment $c): array
    {
        $c->loadMissing(['createdBy', 'assignee', 'source']);

        return [
            'kind' => 'commitment',
            'id' => $c->uuid,
            'title' => $c->title,
            'status' => $c->status,
            'due_at' => $c->due_at?->toIso8601String(),
            'remind_minutes' => $c->remind_minutes,
            'by' => $this->person($me, $c->createdBy),
            'assignee' => $this->person($me, $c->assignee),
            'source' => $this->sourceRef($c->source),
            'responded_at' => $c->responded_at?->toIso8601String(),
            'done_at' => $c->done_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function handoffMeta(Model $me, ChatHandoff $h): array
    {
        $h->loadMissing(['from', 'to', 'source']);

        return [
            'kind' => 'handoff',
            'id' => $h->uuid,
            'note' => $h->note,
            'status' => $h->status,
            'from' => $this->person($me, $h->from),
            'to' => $this->person($me, $h->to),
            'source' => $this->sourceRef($h->source),
            'responded_at' => $h->responded_at?->toIso8601String(),
            'done_at' => $h->done_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function agreementMeta(Model $me, ChatAgreement $a): array
    {
        $a->loadMissing(['createdBy', 'source']);
        $parties = ChatParticipant::query()->whereIn('id', $a->partyIds())->get()->keyBy('id');
        $approvals = (array) $a->approvals;

        return [
            'kind' => 'agreement',
            'id' => $a->uuid,
            'title' => $a->title,
            'terms' => array_values((array) $a->terms),
            'version' => $a->version,
            'status' => $a->status,
            'by' => $this->person($me, $a->createdBy),
            'parties' => collect($a->partyIds())->map(fn ($id) => ($parties->get($id) ? $this->person($me, $parties->get($id)) : ['participant_id' => $id]) + [
                'decision' => $approvals[(string) $id]['decision'] ?? null,
                'at' => $approvals[(string) $id]['at'] ?? null,
            ])->values()->all(),
            'source' => $this->sourceRef($a->source),
            'agreed_at' => $a->agreed_at?->toIso8601String(),
            // Said on the card itself: nothing is paid, booked or charged from it.
            'record_only' => true,
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function assertUsable(ChatConversation $conversation, ?ChatMessage $source): void
    {
        if ($conversation->isChannel()) {
            throw new ChatException('life_unavailable', 422);
        }
        if ($source !== null && ($source->conversation_id !== $conversation->id || $source->isGone() || in_array($source->type, [MessageType::System, MessageType::Call], true))) {
            throw new ChatException('life_unavailable', 422);
        }
    }

    private function member(ChatConversation $conversation, ?int $participantId): ?ChatParticipant
    {
        if ($participantId === null) {
            return null;
        }

        return $conversation->activeParticipants()->whereKey($participantId)->first() ?? throw new ChatException('life_not_member', 422);
    }

    private function other(ChatConversation $conversation, ChatParticipant $mine): ?ChatParticipant
    {
        return $conversation->activeParticipants()->whereKeyNot($mine->id)->first();
    }

    /**
     * @param  list<int>|null  $chosen
     * @return list<int>
     */
    private function parties(ChatConversation $conversation, ChatParticipant $mine, ?array $chosen): array
    {
        $active = $conversation->activeParticipants()->pluck('id')->map(fn ($id) => (int) $id);
        $ids = $chosen === null || $chosen === [] ? ($conversation->isGroup() ? collect() : $active) : collect($chosen)->map(fn ($id) => (int) $id);
        $ids = $ids->push($mine->id)->unique()->values();
        if ($ids->count() < 2 || $ids->count() > 20 || $ids->diff($active)->isNotEmpty()) {
            throw new ChatException('agreement_parties', 422);
        }

        return $ids->all();
    }

    private function party(Model $me, ChatAgreement $a): ChatParticipant
    {
        $mine = $this->conversations->participantOf($me, $a->conversation, true);
        if (! in_array($mine->id, $a->partyIds(), true)) {
            throw new ChatException('life_not_yours', 403);
        }

        return $mine;
    }

    /**
     * @param  list<array<string, mixed>>  $terms
     * @return list<array{label: string, value: string}>
     */
    private function cleanTerms(array $terms): array
    {
        $out = [];
        foreach ($terms as $t) {
            $label = trim((string) ($t['label'] ?? ''));
            $value = trim((string) ($t['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                $out[] = ['label' => mb_substr($label, 0, 60), 'value' => mb_substr($value, 0, 500)];
            }
        }
        if ($out === [] || count($out) > 12) {
            throw new ChatException('agreement_terms', 422);
        }

        return $out;
    }

    private function titleFrom(?string $title, ?ChatMessage $source): string
    {
        $title = trim((string) ($title ?: Str::limit((string) $source?->body, 280)));
        if ($title === '') {
            throw new ChatException('life_title', 422);
        }

        return mb_substr($title, 0, 300);
    }

    /** @return array<string, mixed>|null */
    private function person(Model $me, ?ChatParticipant $p): ?array
    {
        if ($p === null) {
            return null;
        }
        $profile = $this->directory->profile($me, $p->participant_type, $p->participant_id);

        return ['participant_id' => $p->id, 'key' => $p->key(), 'name' => $profile['account_name'] ?? $profile['name'] ?? null, 'avatar' => $profile['avatar'] ?? null];
    }

    /** @return array<string, mixed>|null */
    private function sourceRef(?ChatMessage $m): ?array
    {
        return $m === null || $m->isGone() ? null : ['id' => $m->uuid, 'type' => $m->type->value, 'preview' => Str::limit((string) $m->body, 140) ?: null];
    }

    /** @return array<string, mixed>|null */
    private function conversationRef(?ChatConversation $c): ?array
    {
        return $c === null ? null : ['id' => $c->uuid, 'type' => $c->type->value, 'name' => $c->group?->name];
    }

    /** @param  array<string, mixed>  $meta */
    private function refreshCard(Model $me, ?ChatMessage $card, array $meta): void
    {
        if ($card === null) {
            return;
        }
        $card->update(['meta' => $meta]);
        $this->messages->rebroadcast($card->refresh());
    }

    /** A push to the other side: "Sara accepted: Send the contract". */
    private function tell(?ChatParticipant $to, string $what, Model $actor, string $title, ChatConversation $conversation, ?ChatMessage $card): void
    {
        if ($to === null) {
            return;
        }
        $name = (string) ($actor->name ?? '');
        $headings = [];
        foreach (LocaleResolver::supported() as $locale) {
            $headings[$locale] = __('chat.push.'.$what, ['name' => $name], $locale);
        }
        $this->push->toAccounts([[$to->participant_type, (int) $to->participant_id]], $headings, ['en' => Str::limit($title, 160)],
            ['type' => 'chat', 'event' => 'chat.life.'.$what, 'conversation_uuid' => $conversation->uuid, 'message_uuid' => $card?->uuid]);
    }

    /** @return array<string, string> */
    private function langs(string $key): array
    {
        $out = [];
        foreach (LocaleResolver::supported() as $locale) {
            $out[$locale] = __($key, [], $locale);
        }

        return $out;
    }

    /** @param  array<string, mixed>|null  $data */
    private function log(string $type, int $id, ChatParticipant $actor, string $action, ?array $data = null): void
    {
        DB::table('chat_life_log')->insert(['subject_type' => $type, 'subject_id' => $id, 'actor_participant_id' => $actor->id, 'action' => $action, 'data' => $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, 'created_at' => now()]);
    }
}
