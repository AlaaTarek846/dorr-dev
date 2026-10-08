<?php

namespace Modules\Chat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Models\ChatBlock;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupDecision;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatPrivacyCircle;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * Overviews over my chats, read by the server from what I can see — no AI:
 *  - 114 why a chat is in the priority inbox;
 *  - 123 "what I missed" since a time: mentions, replies to me, replies I owe, missed calls,
 *    reminders that went off, new group decisions, and the chats with the most unread;
 *  - 66  the money in one chat: transfers, requests and splits, with my totals;
 *  - 127 the privacy center: every privacy choice in one place.
 */
class ChatOverviewService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ParticipantDirectory $directory,
    ) {}

    // ------------------------------------------------------------------ 114

    /**
     * @param  Collection<int, ChatParticipant>  $participants
     * @return array<int, list<string>> participant id → mention · urgent · owed · direct
     */
    public function priorityReasons(Collection $participants): array
    {
        $ids = $participants->pluck('id');
        $owed = DB::table('chat_message_user_states')->whereIn('participant_id', $ids)->whereNotNull('follow_up_at')->distinct()->pluck('participant_id')->flip();
        $out = [];
        foreach ($participants as $p) {
            $urgent = ChatMessage::query()->where('conversation_id', $p->conversation_id)->where('is_urgent', true)
                ->where('id', '>', (int) $p->last_read_message_id)->whereNull('deleted_for_everyone_at')->exists();
            $out[$p->id] = array_values(array_filter([
                $p->has_unread_mention ? 'mention' : null,
                $urgent ? 'urgent' : null,
                $owed->has($p->id) ? 'owed' : null,
                $p->unread_count > 0 && ! $p->conversation?->isGroup() ? 'direct' : null,
            ]));
        }

        return $out;
    }

    // ------------------------------------------------------------------ 123

    /**
     * @return array<string, mixed>
     */
    public function catchUp(Model $me, ?string $since): array
    {
        $from = $since ? CarbonImmutable::parse($since)->utc() : now()->subDay()->toImmutable();
        $from = $from->max(now()->subDays(7)->toImmutable());
        $mine = $this->visibleParticipants($me);
        $ids = $mine->pluck('id');
        $byConversation = $mine->keyBy('conversation_id');

        $base = fn () => ChatMessage::query()->whereIn('conversation_id', $mine->pluck('conversation_id'))->where('created_at', '>=', $from)
            ->whereNull('deleted_for_everyone_at')->whereNotNull('sender_type')->where('view_once', false)
            ->whereNot(fn ($q) => $q->where('sender_type', ParticipantType::aliasFor($me))->where('sender_id', $me->getKey()));

        $mentions = $base()->whereNotNull('mentions')->latest('id')->limit(300)->get()
            ->filter(fn (ChatMessage $m) => in_array((int) ($byConversation[$m->conversation_id]->id ?? 0), array_map('intval', (array) $m->mentions), true))->take(20);
        $myMessages = ChatMessage::query()->whereIn('conversation_id', $mine->pluck('conversation_id'))
            ->where('sender_type', ParticipantType::aliasFor($me))->where('sender_id', $me->getKey())->select('id');
        $replies = $base()->whereIn('reply_to_id', $myMessages)->latest('id')->limit(20)->get();
        $urgent = $base()->where('is_urgent', true)->latest('id')->limit(10)->get();

        $owed = ChatMessage::query()->whereIn('id', DB::table('chat_message_user_states')->whereIn('participant_id', $ids)->whereNotNull('follow_up_at')->pluck('message_id'))
            ->whereNull('deleted_for_everyone_at')->latest('id')->limit(20)->get();

        $missedCalls = DB::table('chat_call_participants as cp')->join('chat_calls as c', 'c.id', '=', 'cp.call_id')
            ->whereIn('cp.participant_id', $ids)->where('cp.status', 'missed')->where('c.created_at', '>=', $from)
            ->orderByDesc('c.id')->limit(20)->get(['c.uuid', 'c.type', 'c.conversation_id', 'c.initiator_participant_id', 'c.created_at']);

        $reminders = DB::table('chat_message_reminders as r')->join('chat_messages as m', 'm.id', '=', 'r.message_id')
            ->whereIn('r.participant_id', $ids)->whereNotNull('r.sent_at')->where('r.sent_at', '>=', $from)
            ->orderByDesc('r.sent_at')->limit(20)->get(['r.note', 'r.sent_at', 'm.id as message_id']);

        $decisions = ChatGroupDecision::query()->whereIn('conversation_id', $mine->pluck('conversation_id'))->where('status', 'open')
            ->where('created_at', '>=', $from)->latest('id')->limit(10)->get();

        $unread = $mine->filter(fn ($p) => $p->unread_count > 0)->sortByDesc('unread_count')->take(8);

        $all = $mentions->concat($replies)->concat($urgent)->concat($owed);
        $this->directory->prime($me, $all->map(fn ($m) => [$m->sender_type, $m->sender_id]));
        $titles = $this->titles($me, $mine);
        $ref = fn (ChatMessage $m) => [
            'message_id' => $m->uuid,
            'conversation_id' => $titles[$m->conversation_id]['id'] ?? null,
            'chat' => $titles[$m->conversation_id]['title'] ?? null,
            'sender' => $this->directory->profile($me, $m->sender_type, $m->sender_id)['name'] ?? null,
            'excerpt' => Str::limit(trim(ChatPushNotifier::plain((string) $m->body)) ?: '['.$m->type->value.']', 140),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
        $callers = ChatParticipant::query()->whereIn('id', $missedCalls->pluck('initiator_participant_id'))->get()->keyBy('id');
        $reminderMessages = ChatMessage::query()->whereIn('id', $reminders->pluck('message_id'))->get()->keyBy('id');

        return [
            'since' => $from->toIso8601String(),
            'mentions' => $mentions->values()->map($ref)->all(),
            'replies' => $replies->map($ref)->all(),
            'urgent' => $urgent->map($ref)->all(),
            'owed' => $owed->filter(fn ($m) => isset($titles[$m->conversation_id]))->values()->map($ref)->all(),
            'missed_calls' => $missedCalls->map(fn ($c) => [
                'id' => $c->uuid, 'type' => $c->type,
                'conversation_id' => $titles[$c->conversation_id]['id'] ?? null, 'chat' => $titles[$c->conversation_id]['title'] ?? null,
                'caller' => ($p = $callers[$c->initiator_participant_id] ?? null) ? ($this->directory->profile($me, $p->participant_type, $p->participant_id)['name'] ?? null) : null,
                'created_at' => CarbonImmutable::parse($c->created_at, 'UTC')->toIso8601String(),
            ])->values()->all(),
            'reminders' => $reminders->map(fn ($r) => ($m = $reminderMessages[$r->message_id] ?? null) ? $ref($m) + ['note' => $r->note] : null)->filter()->values()->all(),
            'decisions' => $decisions->map(fn ($d) => [
                'id' => $d->uuid, 'title' => $d->title, 'conversation_id' => $titles[$d->conversation_id]['id'] ?? null, 'chat' => $titles[$d->conversation_id]['title'] ?? null,
                'deadline_at' => $d->deadline_at?->toIso8601String(),
            ])->values()->all(),
            'unread_chats' => $unread->map(fn ($p) => ['conversation_id' => $titles[$p->conversation_id]['id'] ?? null, 'chat' => $titles[$p->conversation_id]['title'] ?? null, 'unread' => $p->unread_count, 'mention' => (bool) $p->has_unread_mention])->values()->all(),
        ];
    }

    /**
     * My chats an overview may read: not left, not deleted, not locked, not in a locked or hidden
     * circle.
     *
     * @return Collection<int, ChatParticipant>
     */
    private function visibleParticipants(Model $me): Collection
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

    // ------------------------------------------------------------------ 66

    /**
     * The money in this chat, as I see it: transfers, requests and splits (newest first), and
     * what I sent, received and still owe / am owed.
     *
     * @return array<string, mixed>
     */
    public function money(Model $me, ChatConversation $conversation): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        $rows = $this->messages->visibleTo($participant, $conversation->messages()->getQuery())
            ->whereIn('type', [MessageType::WalletTransfer->value, MessageType::MoneyRequest->value, MessageType::BillSplit->value])
            ->whereNull('deleted_for_everyone_at')->orderByDesc('id')->limit(100)->get();

        $totals = ['sent_minor' => 0, 'received_minor' => 0, 'i_owe_minor' => 0, 'owed_to_me_minor' => 0];
        $currency = null;
        $items = $rows->map(function (ChatMessage $m) use ($participant, &$totals, &$currency) {
            $meta = (array) $m->meta;
            $mine = $m->isFrom($participant->participant_type, (int) $participant->participant_id);
            $currency ??= $meta['currency'] ?? null;
            $amount = (int) ($meta['amount_minor'] ?? $meta['total_minor'] ?? 0);
            $status = $meta['status'] ?? null;

            if ($m->type === MessageType::WalletTransfer && ! ($meta['is_reversed'] ?? false)) {
                $totals[$mine ? 'sent_minor' : 'received_minor'] += $amount;
            } elseif ($m->type === MessageType::MoneyRequest && $status === 'pending') {
                $totals[$mine ? 'owed_to_me_minor' : 'i_owe_minor'] += $amount;
            } elseif ($m->type === MessageType::BillSplit && $status === 'open') {
                $share = collect($meta['shares'] ?? [])->firstWhere('key', $participant->key());
                if (! $mine && $share !== null && ($share['status'] ?? 'pending') === 'pending') {
                    $totals['i_owe_minor'] += (int) ($share['amount_minor'] ?? 0);
                } elseif ($mine) {
                    $totals['owed_to_me_minor'] += collect($meta['shares'] ?? [])->where('status', 'pending')->reject(fn ($s) => ($s['key'] ?? null) === $participant->key())->sum('amount_minor');
                }
            }

            return [
                'message_id' => $m->uuid,
                'type' => $m->type->value,
                'amount_minor' => $amount,
                'currency' => $meta['currency'] ?? null,
                'status' => $m->type === MessageType::WalletTransfer ? (($meta['is_reversed'] ?? false) ? 'reversed' : 'done') : $status,
                'is_mine' => $mine,
                'note' => $m->body ? Str::limit(ChatPushNotifier::plain((string) $m->body), 80) : null,
                'created_at' => $m->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return ['items' => $items, 'totals' => $totals + ['currency' => $currency]];
    }

    // ------------------------------------------------------------------ 127

    /**
     * Everything about my privacy in one place: who sees what, notifications, privacy mode and
     * quiet, circles, locked chats (and which have their own PIN), blocked people, my @username,
     * and what the AI may read.
     *
     * @return array<string, mixed>
     */
    public function privacyCenter(Model $me): array
    {
        $settings = app(ChatPrivacy::class)->settingsFor($me);
        $mine = ChatParticipant::query()->of($me)->where('is_deleted', false);
        $circles = ChatPrivacyCircle::query()->ownedBy($me)->get();

        return [
            'who_sees' => [
                'last_seen' => $settings->last_seen?->value ?? 'everyone',
                'profile_photo' => $settings->profile_photo?->value ?? 'everyone',
                'status' => $settings->status_audience?->value ?? 'contacts',
                'read_receipts' => (bool) ($settings->read_receipts ?? true),
            ],
            'who_can' => [
                'message' => $settings->who_can_message?->value ?? 'everyone',
                'add_to_groups' => $settings->who_can_add_to_groups?->value ?? 'everyone',
                'call' => $settings->who_can_call?->value ?? 'everyone',
                'urgent' => $settings->who_can_urgent?->value ?? 'contacts',
            ],
            'notifications' => $settings->notification_privacy ?: 'all',
            'privacy_mode' => ['on' => $settings->privacyModeOn(), 'scheduled' => ! empty($settings->privacy_schedule)],
            'quiet' => ['on' => $settings->quietOn(), 'scheduled' => ! empty($settings->quiet_schedule)],
            'block_screenshots' => (bool) $settings->block_screenshots,
            'circles' => ['count' => $circles->count(), 'locked' => $circles->where('locked', true)->count(), 'hidden' => $circles->where('hide_from_list', true)->count()],
            'locked_chats' => (clone $mine)->where('is_locked', true)->count(),
            'pin_locked_chats' => (clone $mine)->whereNotNull('lock_pin_hash')->count(),
            'blocked' => ChatBlock::query()->where('blocker_type', ParticipantType::aliasFor($me))->where('blocker_id', $me->getKey())->count(),
            'username' => $me->chat_username ?? null,
            'ai' => ['enabled' => ChatSetting::current()->aiEnabled(), 'reads' => __('chat.privacy_center.ai_reads')],
        ];
    }
}
