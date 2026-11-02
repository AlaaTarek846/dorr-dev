<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Enums\GroupKind;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupHelpRequest;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\GroupPermissions;
use Modules\Chat\Support\GroupScope;

/**
 * Asking for help in an educational or work group (docs/groups_tasks.md GR-031, GR-041). A
 * request goes to one person, or to the helpers: in a class the teachers (`lessons.manage` or
 * `grading`), in a work group the whole team. Only the asker, the person asked and the helpers see
 * it; any of them (but the asker) answers; the asker closes it.
 */
class GroupHelpService
{
    public const PER_PAGE = 15;

    public function __construct(private readonly GroupNotifier $notifier) {}

    /** @return array{requests: list<array<string, mixed>>, has_more: bool} */
    public function list(Model $me, ChatConversation $conversation, string $status, int $page): array
    {
        $member = $this->member($me, $conversation);
        $helper = $this->isHelper($member, $conversation);

        $query = ChatGroupHelpRequest::query()->where('conversation_id', $conversation->id)
            ->where(fn ($q) => $q->where('participant_id', $member->id)->orWhere('to_participant_id', $member->id)
                ->when($helper, fn ($q) => $q->orWhereNull('to_participant_id')));
        $status === 'open' ? $query->whereIn('status', ['open', 'answered']) : $query->where('status', 'closed');
        $rows = $query->latest('id')->forPage(max(1, $page), self::PER_PAGE)->limit(self::PER_PAGE + 1)->get();

        return [
            'requests' => $rows->take(self::PER_PAGE)->map(fn ($r) => $this->present($me, $member, $conversation, $r))->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /** @return array<string, mixed> */
    public function ask(Model $me, ChatConversation $conversation, string $question, ?int $toParticipantId): array
    {
        $member = $this->member($me, $conversation);
        GroupLifecycleService::assertWritable($conversation);

        $to = null;
        if ($toParticipantId !== null) {
            $to = $conversation->activeParticipants()->find($toParticipantId) ?? throw new ChatException('not_a_member', 404);
            if ($to->id === $member->id) {
                throw new ChatException('help_to_self', 422);
            }
        }

        $request = ChatGroupHelpRequest::query()->create([
            'conversation_id' => $conversation->id, 'participant_id' => $member->id,
            'to_participant_id' => $to?->id, 'question' => trim($question),
        ]);

        $recipients = $to !== null ? collect([$to]) : $this->helpers($conversation)->reject(fn ($p) => $p->id === $member->id);
        $this->notifier->notify($conversation, $recipients, 'help', 'help_asked', ['question' => mb_strimwidth($request->question, 0, 80, '…')], ['help_id' => $request->uuid]);

        return $this->present($me, $member, $conversation, $request);
    }

    /** @return array<string, mixed> */
    public function answer(Model $me, ChatConversation $conversation, string $uuid, string $answer): array
    {
        $member = $this->member($me, $conversation);
        $request = $this->request($member, $conversation, $uuid);
        if ($request->participant_id === $member->id) {
            throw new ChatException('help_answer_own', 422);
        }
        if ($request->status === 'closed') {
            throw new ChatException('help_closed', 409);
        }

        $request->update(['answer' => trim($answer), 'status' => 'answered', 'answered_by_participant_id' => $member->id, 'answered_at' => now()]);
        $asker = ChatParticipant::query()->find($request->participant_id);
        if ($asker !== null) {
            $this->notifier->notify($conversation, [$asker], 'help', 'help_answered', [], ['help_id' => $request->uuid]);
        }

        return $this->present($me, $member, $conversation, $request->refresh());
    }

    /** The asker closes it (solved, or no longer needed). */
    public function close(Model $me, ChatConversation $conversation, string $uuid): array
    {
        $member = $this->member($me, $conversation);
        $request = $this->request($member, $conversation, $uuid);
        if ($request->participant_id !== $member->id) {
            throw new ChatException('group_permission_denied', 403);
        }
        $request->update(['status' => 'closed']);

        return $this->present($me, $member, $conversation, $request->refresh());
    }

    // ------------------------------------------------------------------ helpers

    private function member(Model $me, ChatConversation $conversation): ChatParticipant
    {
        if (! GroupLifecycleService::isKindGroup($conversation)) {
            throw new ChatException('group_kind_required', 422);
        }

        return GroupScope::member($me, $conversation->id) ?? throw ChatException::notParticipant();
    }

    /** In a class: the teachers; in a work group: everyone. */
    private function isHelper(ChatParticipant $member, ChatConversation $conversation): bool
    {
        if ($conversation->group->kind === GroupKind::Work) {
            return true;
        }
        $perms = GroupPermissions::ofParticipant($member, $conversation);

        return in_array('lessons.manage', $perms, true) || in_array('grading', $perms, true);
    }

    /** @return \Illuminate\Support\Collection<int, ChatParticipant> */
    private function helpers(ChatConversation $conversation)
    {
        $members = $conversation->activeParticipants()->get();

        return $conversation->group->kind === GroupKind::Work ? $members
            : $members->filter(fn (ChatParticipant $p) => $p->role === ParticipantRole::Owner || $this->isHelper($p, $conversation))->values();
    }

    private function request(ChatParticipant $member, ChatConversation $conversation, string $uuid): ChatGroupHelpRequest
    {
        $request = ChatGroupHelpRequest::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->first();
        $sees = $request !== null && ($request->participant_id === $member->id || $request->to_participant_id === $member->id
            || ($request->to_participant_id === null && $this->isHelper($member, $conversation)));

        if (! $sees) {
            throw new ChatException('help_not_found', 404);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function present(Model $me, ChatParticipant $member, ChatConversation $conversation, ChatGroupHelpRequest $r): array
    {
        $directory = app(\Modules\Chat\Support\ParticipantDirectory::class);
        $person = function (?int $id) use ($me, $directory) {
            $row = $id ? ChatParticipant::query()->find($id) : null;

            return $row ? ['participant_id' => $row->id, 'profile' => $directory->profile($me, $row->participant_type, $row->participant_id)] : null;
        };

        return [
            'id' => $r->uuid,
            'question' => $r->question,
            'status' => $r->status,
            'answer' => $r->answer,
            'asker' => $person($r->participant_id),
            'to' => $person($r->to_participant_id),
            'answered_by' => $person($r->answered_by_participant_id),
            'can' => [
                'answer' => $r->status !== 'closed' && $r->participant_id !== $member->id,
                'close' => $r->status !== 'closed' && $r->participant_id === $member->id,
            ],
            'created_at' => $r->created_at?->toIso8601String(),
            'answered_at' => $r->answered_at?->toIso8601String(),
        ];
    }
}
