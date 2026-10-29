<?php

namespace Modules\Chat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Models\ChatAgreement;
use Modules\Chat\Models\ChatCalendarItem;
use Modules\Chat\Models\ChatCommitment;
use Modules\Chat\Models\ChatGroupDecision;
use Modules\Chat\Models\ChatGroupJoinRequest;
use Modules\Chat\Models\ChatHandoff;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatPrivacyCircle;
use Modules\Chat\Models\ChatTask;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * The life inbox (spec 147): what needs something from me, not how many messages came —
 *  - reply: messages I marked "needs an answer", and recent mentions of me;
 *  - pay: money requests and my shares of splits still waiting;
 *  - approve: commitments, handoffs and agreements waiting for my answer, join requests and
 *    decisions in groups I run, message requests;
 *  - follow up: what I took on (due soon or late), and what I'm waiting for from others;
 *  - appointments: today's and tomorrow's in my calendar, and my tasks due.
 * Only chats an overview may read (no locked chats, no hidden circles). Each item opens where it is.
 */
class LifeInboxService
{
    public function __construct(
        private readonly ParticipantDirectory $directory,
        private readonly ConversationService $conversations,
    ) {}

    /**
     * @return array{data: array<string, mixed>, paginator: LengthAwarePaginator}
     */
    public function inbox(Model $me, string $zone, ?string $section = null, int $page = 1, int $perPage = 15, ?string $q = null): array
    {
        $mine = $this->visible($me);
        $byConversation = $mine->keyBy('conversation_id');
        $ids = $mine->pluck('id');
        $allMine = ChatParticipant::query()->of($me)->pluck('id');
        $titles = $this->titles($me, $mine);
        $myType = ParticipantType::aliasFor($me);
        $chat = fn (?int $conversationId) => ['conversation_id' => $titles[$conversationId]['id'] ?? null, 'chat' => $titles[$conversationId]['title'] ?? null];
        $name = function (?ChatParticipant $p) use ($me) {
            return $p === null ? null : ($this->directory->profile($me, $p->participant_type, $p->participant_id)['name'] ?? null);
        };
        $excerpt = fn (ChatMessage $m) => Str::limit(trim(ChatPushNotifier::plain((string) $m->body)) ?: '['.$m->type->value.']', 120);

        // ---------------------------------------------------------------- reply
        $owed = ChatMessage::query()->whereIn('id', DB::table('chat_message_user_states')->whereIn('participant_id', $ids)->whereNotNull('follow_up_at')->pluck('message_id'))
            ->whereNull('deleted_for_everyone_at')->latest('id')->limit(30)->get();
        $mentions = ChatMessage::query()->whereIn('conversation_id', $mine->pluck('conversation_id'))->where('created_at', '>=', now()->subDays(3))
            ->whereNull('deleted_for_everyone_at')->whereNotNull('mentions')->whereNotNull('sender_type')
            ->whereNot(fn ($q) => $q->where('sender_type', $myType)->where('sender_id', $me->getKey()))
            ->latest('id')->limit(200)->get()
            ->filter(fn (ChatMessage $m) => in_array((int) ($byConversation[$m->conversation_id]->id ?? 0), array_map('intval', (array) $m->mentions), true))
            // Answered already: I wrote in that chat after it.
            ->reject(fn (ChatMessage $m) => ChatMessage::query()->where('conversation_id', $m->conversation_id)->where('id', '>', $m->id)->where('sender_type', $myType)->where('sender_id', $me->getKey())->exists())
            ->take(15);
        $this->directory->prime($me, $owed->concat($mentions)->map(fn ($m) => [$m->sender_type, $m->sender_id]));
        $reply = $owed->concat($mentions)->unique('id')->filter(fn ($m) => isset($titles[$m->conversation_id]))->map(fn (ChatMessage $m) => [
            'kind' => $owed->contains('id', $m->id) ? 'needs_reply' : 'mention',
            'title' => $excerpt($m),
            'by' => $this->directory->profile($me, $m->sender_type, $m->sender_id)['name'] ?? null,
            'message_id' => $m->uuid,
            'at' => $m->created_at?->toIso8601String(),
        ] + $chat($m->conversation_id))->values()->all();

        // ---------------------------------------------------------------- pay
        $pay = ChatMessage::query()->whereIn('conversation_id', $mine->pluck('conversation_id'))
            ->whereIn('type', [MessageType::MoneyRequest->value, MessageType::BillSplit->value])->whereNull('deleted_for_everyone_at')
            ->where('created_at', '>=', now()->subDays(60))
            ->whereNot(fn ($q) => $q->where('sender_type', $myType)->where('sender_id', $me->getKey()))
            ->latest('id')->limit(100)->get()
            ->map(function (ChatMessage $m) use ($byConversation, $chat, $me) {
                $meta = (array) $m->meta;
                $myKey = $byConversation[$m->conversation_id]?->key();
                if ($m->type === MessageType::MoneyRequest) {
                    $due = ($meta['status'] ?? null) === 'pending' ? (int) ($meta['amount_minor'] ?? 0) : 0;
                } else {
                    $share = collect($meta['shares'] ?? [])->firstWhere('key', $myKey);
                    $due = ($meta['status'] ?? null) === 'open' && ($share['status'] ?? null) === 'pending' ? (int) ($share['amount_minor'] ?? 0) : 0;
                }
                if ($due <= 0) {
                    return null;
                }

                return [
                    'kind' => $m->type->value,
                    'title' => $m->body ? Str::limit(ChatPushNotifier::plain((string) $m->body), 80) : null,
                    'by' => $this->directory->profile($me, $m->sender_type, $m->sender_id)['name'] ?? null,
                    'amount_minor' => $due,
                    'currency' => $meta['currency'] ?? null,
                    'message_id' => $m->uuid,
                    'at' => $m->created_at?->toIso8601String(),
                ] + $chat($m->conversation_id);
            })->filter()->values()->all();

        // ---------------------------------------------------------------- approve
        $approve = collect();
        ChatCommitment::query()->whereIn('assignee_participant_id', $allMine)->where('status', 'proposed')->with(['createdBy', 'card'])->latest('id')->limit(30)->get()
            ->each(fn ($c) => $approve->push(['kind' => 'commitment', 'id' => $c->uuid, 'title' => $c->title, 'by' => $name($c->createdBy), 'due_at' => $c->due_at?->toIso8601String(), 'message_id' => $c->card?->uuid, 'at' => $c->created_at?->toIso8601String()] + $chat($c->conversation_id)));
        ChatHandoff::query()->whereIn('to_participant_id', $allMine)->where('status', 'pending')->with(['from', 'card', 'source'])->latest('id')->limit(30)->get()
            ->each(fn ($h) => $approve->push(['kind' => 'handoff', 'id' => $h->uuid, 'title' => $h->note ?? Str::limit((string) $h->source?->body, 120), 'by' => $name($h->from), 'message_id' => $h->card?->uuid, 'at' => $h->created_at?->toIso8601String()] + $chat($h->conversation_id)));
        ChatAgreement::query()->whereIn('conversation_id', $mine->pluck('conversation_id'))->where('status', 'proposed')->with(['createdBy', 'card'])->latest('id')->limit(30)->get()
            ->filter(function (ChatAgreement $a) use ($byConversation) {
                $pid = $byConversation[$a->conversation_id]?->id;

                return $pid !== null && in_array($pid, $a->partyIds(), true) && (((array) $a->approvals)[(string) $pid]['decision'] ?? null) !== 'accepted';
            })
            ->each(fn ($a) => $approve->push(['kind' => 'agreement', 'id' => $a->uuid, 'title' => $a->title, 'by' => $name($a->createdBy), 'version' => $a->version, 'message_id' => $a->card?->uuid, 'at' => $a->updated_at?->toIso8601String()] + $chat($a->conversation_id)));
        $adminOf = $mine->filter(fn ($p) => $p->isAdmin() && $p->conversation?->isGroup())->pluck('conversation_id');
        if ($adminOf->isNotEmpty()) {
            ChatGroupJoinRequest::query()->whereIn('conversation_id', $adminOf)->where('status', 'pending')->latest('id')->limit(30)->get()
                ->each(fn ($r) => $approve->push(['kind' => 'join_request', 'title' => $this->directory->profile($me, $r->requester_type, $r->requester_id)['name'] ?? null, 'at' => $r->created_at?->toIso8601String()] + $chat($r->conversation_id)));
            ChatGroupDecision::query()->whereIn('conversation_id', $adminOf)->where('status', 'open')->latest('id')->limit(20)->get()
                ->each(fn ($d) => $approve->push(['kind' => 'decision', 'id' => $d->uuid, 'title' => $d->title, 'due_at' => $d->deadline_at?->toIso8601String(), 'at' => $d->created_at?->toIso8601String()] + $chat($d->conversation_id)));
        }
        $requests = $this->conversations->requestsCount($me);

        // ---------------------------------------------------------------- follow up
        $follow = collect();
        ChatCommitment::query()->whereIn('assignee_participant_id', $allMine)->where('status', 'accepted')->with(['createdBy', 'card'])
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->limit(30)->get()
            ->each(fn ($c) => $follow->push(['kind' => 'my_commitment', 'id' => $c->uuid, 'title' => $c->title, 'by' => $name($c->createdBy), 'due_at' => $c->due_at?->toIso8601String(), 'late' => $c->due_at?->isPast() ?? false, 'message_id' => $c->card?->uuid] + $chat($c->conversation_id)));
        ChatHandoff::query()->whereIn('to_participant_id', $allMine)->where('status', 'accepted')->with(['from', 'card', 'source'])->latest('id')->limit(30)->get()
            ->each(fn ($h) => $follow->push(['kind' => 'my_handoff', 'id' => $h->uuid, 'title' => $h->note ?? Str::limit((string) $h->source?->body, 120), 'by' => $name($h->from), 'message_id' => $h->card?->uuid] + $chat($h->conversation_id)));
        ChatCommitment::query()->whereIn('created_by_participant_id', $allMine)->whereNotIn('assignee_participant_id', $allMine)->whereIn('status', ChatCommitment::OPEN)->with(['assignee', 'card'])
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->limit(30)->get()
            ->each(fn ($c) => $follow->push(['kind' => 'waiting_for', 'id' => $c->uuid, 'title' => $c->title, 'by' => $name($c->assignee), 'status' => $c->status, 'due_at' => $c->due_at?->toIso8601String(), 'late' => $c->due_at?->isPast() ?? false, 'message_id' => $c->card?->uuid] + $chat($c->conversation_id)));

        // ---------------------------------------------------------------- appointments
        $from = CarbonImmutable::now($zone)->startOfDay()->utc();
        $to = $from->addDays(2);
        $appointments = ChatCalendarItem::query()->where('owner_type', $myType)->where('owner_id', $me->getKey())
            ->where(fn ($q) => $q->whereBetween('starts_at', [$from, $to])->orWhere(fn ($d) => $d->where('all_day', true)->whereBetween('date', [$from->setTimezone($zone)->toDateString(), $to->setTimezone($zone)->subDay()->toDateString()])))
            ->orderBy('starts_at')->limit(30)->get()
            ->map(fn (ChatCalendarItem $i) => ['kind' => 'appointment', 'id' => $i->uuid, 'title' => $i->title, 'at' => $i->starts_at?->toIso8601String(), 'all_day' => (bool) $i->all_day, 'date' => $i->date?->toDateString(), 'location' => $i->location])
            ->values();
        $tasks = ChatTask::query()->where('owner_type', $myType)->where('owner_id', $me->getKey())->whereNull('done_at')->whereNotNull('due_at')->where('due_at', '<', $to)
            ->orderBy('due_at')->limit(30)->get()
            ->map(fn (ChatTask $t) => ['kind' => 'task', 'id' => $t->uuid, 'title' => $t->text, 'at' => $t->due_at?->toIso8601String(), 'late' => $t->due_at?->isPast() ?? false]);

        $order = ['reply', 'pay', 'approve', 'follow', 'appointments'];
        $sections = [
            'reply' => $reply,
            'pay' => $pay,
            'approve' => $approve->sortByDesc('at')->values()->all(),
            'follow' => $follow->values()->all(),
            'appointments' => $appointments->concat($tasks)->sortBy('at')->values()->all(),
        ];
        $needle = mb_strtolower(trim((string) $q));
        if ($needle !== '') {
            foreach ($sections as $key => $list) {
                $sections[$key] = array_values(array_filter($list, fn (array $item) => $this->matchesSearch($item, $needle)));
            }
        }
        $counts = collect($sections)->map(fn ($s) => count($s))->all() + ['message_requests' => $requests];
        $perPage = max(1, min($perPage, 50));
        $page = max(1, $page);
        $active = $section !== null && isset($sections[$section])
            ? $section
            : (collect($order)->first(fn ($key) => ($counts[$key] ?? 0) > 0) ?? 'reply');
        $all = array_values($sections[$active] ?? []);
        $slice = array_values(array_slice($all, ($page - 1) * $perPage, $perPage));
        $lists = array_fill_keys($order, []);
        if ($section !== null) {
            $lists[$active] = $slice;
        } else {
            foreach ($order as $key) {
                $lists[$key] = array_values(array_slice($sections[$key], 0, $perPage));
            }
        }

        return [
            'data' => $lists + [
                'message_requests' => $requests,
                'counts' => $counts,
                'total' => collect($sections)->sum(fn ($s) => count($s)) + $requests,
            ],
            'paginator' => new LengthAwarePaginator($slice, count($all), $perPage, $page),
        ];
    }

    /** Title, who, chat name, or location. */
    private function matchesSearch(array $item, string $needle): bool
    {
        $hay = mb_strtolower(trim(implode(' ', array_filter([
            $item['title'] ?? null,
            $item['by'] ?? null,
            $item['chat'] ?? null,
            $item['location'] ?? null,
        ], fn ($v) => is_string($v) && $v !== ''))));

        return $hay !== '' && str_contains($hay, $needle);
    }

    /** @return Collection<int, ChatParticipant> */
    private function visible(Model $me): Collection
    {
        $hidden = ChatPrivacyCircle::query()->ownedBy($me)->where(fn ($q) => $q->where('locked', true)->orWhere('hide_from_list', true))->pluck('id');

        return ChatParticipant::query()->of($me)->whereNull('left_at')->where('is_deleted', false)->where('is_locked', false)
            ->when($hidden->isNotEmpty(), fn ($q) => $q->where(fn ($q) => $q->whereNull('privacy_circle_id')->orWhereNotIn('privacy_circle_id', $hidden)))
            ->with('conversation.group')->get();
    }

    /**
     * @param  Collection<int, ChatParticipant>  $mine
     * @return array<int, array{id: string, title: ?string}>
     */
    private function titles(Model $me, Collection $mine): array
    {
        $myType = ParticipantType::aliasFor($me);
        $direct = $mine->filter(fn ($p) => $p->conversation && ! $p->conversation->isGroup())->pluck('conversation_id');
        $others = ChatParticipant::query()->whereIn('conversation_id', $direct)
            ->where(fn ($q) => $q->where('participant_type', '!=', $myType)->orWhere('participant_id', '!=', $me->getKey()))->get()->keyBy('conversation_id');
        $this->directory->prime($me, $others->map(fn ($o) => [$o->participant_type, $o->participant_id]));

        return $mine->filter(fn ($p) => $p->conversation)->mapWithKeys(fn ($p) => [$p->conversation_id => [
            'id' => $p->conversation->uuid,
            'title' => $p->conversation->isGroup() ? $p->conversation->group?->name
                : ($p->conversation->isSelf() ? __('chat.self_title') : (($o = $others[$p->conversation_id] ?? null) ? ($this->directory->profile($me, $o->participant_type, $o->participant_id)['name'] ?? null) : null)),
        ]])->all();
    }
}
