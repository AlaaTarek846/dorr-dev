<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatReport;
use Modules\Chat\Models\ChatReportType;
use Modules\Chat\Support\ParticipantType;

/**
 * Reporting a chat (docs/chat-plan.md §3 "report"): a reason, optional details, who is reported,
 * and a copy of the last 30 messages *I* can see — never anything hidden from me. Optionally
 * blocks the person (direct chat) or leaves the group, like WhatsApp's "report and block".
 */
class ChatReportService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
    ) {}

    /**
     * @param  array{report_type_id: int, details?: string|null, participant_ids?: list<int>, block?: bool, leave?: bool}  $data
     */
    public function report(Model $me, ChatConversation $conversation, array $data): ChatReport
    {
        $mine = $this->conversations->participantOf($me, $conversation);
        $type = ChatReportType::query()->active()->find($data['report_type_id']) ?? throw new ChatException('report_type_invalid', 422);
        $conversation->loadMissing('participants');

        $reported = $this->reportedParticipants($conversation, $mine, $data['participant_ids'] ?? []);

        $report = DB::transaction(function () use ($conversation, $mine, $type, $data, $reported) {
            $report = ChatReport::query()->create([
                'conversation_id' => $conversation->id,
                'reporter_type' => $mine->participant_type,
                'reporter_id' => $mine->participant_id,
                'report_type_id' => $type->id,
                'details' => $data['details'] ?? null,
                'status' => 'pending',
            ]);

            foreach ($reported as $participant) {
                $report->reportedUsers()->create(['participant_type' => $participant->participant_type, 'participant_id' => $participant->participant_id]);
            }

            $this->copyEvidence($report, $mine);

            return $report;
        });

        if (! empty($data['block']) && ! $conversation->isGroup() && ($peer = $reported->first()?->participant())) {
            app(BlockService::class)->block($me, $peer);
        }
        if (! empty($data['leave']) && $conversation->isGroup() && $mine->isActive()) {
            app(GroupService::class)->leave($me, $conversation);
        }

        return $report;
    }

    /**
     * Direct chat: the other person, always. Group: the members I picked (none = the group itself).
     *
     * @param  list<int>  $ids
     * @return Collection<int, ChatParticipant>
     */
    private function reportedParticipants(ChatConversation $conversation, ChatParticipant $mine, array $ids): Collection
    {
        $others = $conversation->participants->where('id', '!=', $mine->id);

        if (! $conversation->isGroup()) {
            return $others->take(1)->values();
        }

        return $others->whereIn('id', $ids)->values();
    }

    private function copyEvidence(ChatReport $report, ChatParticipant $mine): void
    {
        $messages = $this->messages->visibleTo($mine, ChatMessage::query()->where('conversation_id', $report->conversation_id))
            ->with('media')
            ->orderByDesc('chat_messages.id')
            ->limit(ChatReport::EVIDENCE_MESSAGES)
            ->get()
            ->reverse();

        foreach ($messages as $message) {
            $deleted = $message->deleted_for_everyone_at !== null;

            $report->messages()->create([
                'message_id' => $message->id,
                'sender_type' => $message->sender_type,
                'sender_id' => $message->sender_id,
                'type' => $message->type->value,
                'body' => $deleted ? null : $message->body,
                'attachments' => $deleted ? [] : $message->getMultipleMediaUrls(ChatMessage::ATTACHMENTS),
                'sent_at' => $message->created_at,
            ]);
        }
    }

    // ---------------------------------------------------------------- admin

    /**
     * A report for the admin screens. `$full` adds the copied messages.
     *
     * @return array<string, mixed>
     */
    public function present(ChatReport $report, bool $full = false): array
    {
        $keys = collect([[$report->reporter_type, $report->reporter_id]])
            ->concat($report->reportedUsers->map(fn ($u) => [$u->participant_type, $u->participant_id]));

        if ($full) {
            $keys = $keys->concat($report->messages->filter(fn ($m) => $m->sender_type !== null)->map(fn ($m) => [$m->sender_type, $m->sender_id]));
        }

        $accounts = $this->accounts($keys);
        $person = fn (?string $type, $id) => $type === null ? null : [
            'type' => $type,
            'id' => (int) $id,
            'name' => $accounts["{$type}:{$id}"]->name ?? null,
            'phone' => $accounts["{$type}:{$id}"]->phone ?? null,
        ];
        $conversation = $report->conversation;

        $data = [
            'id' => $report->id,
            'status' => $report->status,
            'type' => $report->type ? ['id' => $report->type->id, 'name' => $report->type->translatedName()] : null,
            'details' => $report->details,
            'reporter' => $person($report->reporter_type, $report->reporter_id),
            'reported' => $report->reportedUsers->map(fn ($u) => $person($u->participant_type, $u->participant_id))->values(),
            'conversation' => $conversation === null ? null : [
                'id' => $conversation->uuid,
                'type' => $conversation->type->value,
                'title' => $conversation->isGroup() ? $conversation->group?->name : null,
            ],
            'messages_count' => $report->messages_count ?? $report->messages()->count(),
            'admin_note' => $report->admin_note,
            'reviewed_by' => $report->reviewer?->name,
            'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
        ];

        if ($full) {
            $data['messages'] = $report->messages->map(fn ($m) => [
                'id' => $m->id,
                'sender' => $person($m->sender_type, $m->sender_id),
                'is_reporter' => $m->sender_type === $report->reporter_type && (int) $m->sender_id === (int) $report->reporter_id,
                'type' => $m->type,
                'body' => $m->body,
                'attachments' => $m->attachments ?? [],
                'sent_at' => $m->sent_at?->toIso8601String(),
            ])->values();
        }

        return $data;
    }

    /**
     * @param  Collection<int, array{0: string, 1: int|string}>  $keys
     * @return array<string, Model>
     */
    private function accounts(Collection $keys): array
    {
        $found = [];

        foreach ($keys->groupBy(0) as $type => $rows) {
            $class = ParticipantType::modelClassFor($type);
            $class::query()->withoutGlobalScopes()->whereIn('id', $rows->pluck(1)->unique()->all())->get()
                ->each(function (Model $account) use (&$found, $type) {
                    $found["{$type}:{$account->getKey()}"] = $account;
                });
        }

        return $found;
    }
}
