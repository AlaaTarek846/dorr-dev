<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\GroupPermissions;
use Modules\Chat\Support\MessageViewContext;
use Modules\Chat\Support\ParticipantDirectory;

/**
 * Educational / work group tools (docs/groups_tasks.md):
 * - GR-008 announcements: posted by `announcements.post`, pinned for 30 days, marked
 *   `meta.announcement` (the app shows them apart); the chat's own push carries them.
 * - GR-009 the member's notice settings for this group.
 * - GR-010 the activity log, for `audit.view`.
 */
class GroupToolsService
{
    private const ANNOUNCEMENT_PIN_SECONDS = 30 * 86400;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly GroupNotifier $notifier,
    ) {}

    public function announce(Model $me, ChatConversation $conversation, string $body): ChatMessage
    {
        $this->assertKind($conversation);
        $this->assertMay($me, $conversation, 'announcements.post');

        $message = $this->messages->send($me, $conversation, ['type' => 'text', 'body' => trim($body)]);
        $message->update(['meta' => array_merge((array) $message->meta, ['announcement' => true])]);
        $this->messages->rebroadcast($message->refresh());
        $this->messages->pin($me, $message, self::ANNOUNCEMENT_PIN_SECONDS);
        ChatGroupAuditLog::record($conversation, $me, 'announcement_posted', 'message:'.$message->uuid);

        return $message;
    }

    /**
     * The latest announcements, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function announcements(Model $me, ChatConversation $conversation): array
    {
        $this->assertKind($conversation);
        $participant = $this->conversations->participantOf($me, $conversation, true);

        $messages = ChatMessage::query()->where('conversation_id', $conversation->id)->where('meta->announcement', true)
            ->whereNull('deleted_for_everyone_at')->latest('id')->limit(50)->with('media')->get()
            ->filter(fn (ChatMessage $m) => ! $m->isGone())->values();
        $context = MessageViewContext::build($me, $participant, $conversation->loadViewParticipants($participant), $messages);

        return collect($this->messages->present($messages, $context))->map(fn ($r) => $r->resolve(request()))->all();
    }

    /**
     * @return array{types: list<string>, muted: list<string>}
     */
    public function notificationPrefs(Model $me, ChatConversation $conversation, ?array $set = null): array
    {
        $this->assertKind($conversation);
        $participant = $this->conversations->participantOf($me, $conversation, true);
        $muted = $set === null ? $this->notifier->mutedFor($participant) : $this->notifier->setPrefs($participant, $set);

        return ['types' => GroupNotifier::TYPES, 'muted' => $muted];
    }

    /**
     * The activity log, newest first, 30 a page.
     *
     * @return array{items: list<array<string, mixed>>, has_more: bool}
     */
    public function auditLog(Model $me, ChatConversation $conversation, int $page = 1): array
    {
        $this->assertKind($conversation);
        $this->assertMay($me, $conversation, 'audit.view');

        $rows = ChatGroupAuditLog::query()->where('conversation_id', $conversation->id)->latest('id')
            ->forPage(max(1, $page), 30)->limit(31)->get();
        $more = $rows->count() > 30;
        $rows = $rows->take(30);

        $directory = app(ParticipantDirectory::class);
        $directory->prime($me, $rows->filter(fn ($r) => $r->actor_type !== null && $r->actor_type !== 'admin')->map(fn ($r) => [$r->actor_type, $r->actor_id]));

        return [
            'items' => $rows->map(fn (ChatGroupAuditLog $r) => [
                'id' => $r->id,
                'action' => $r->action,
                'text' => __('chat.audit.'.$r->action),
                'actor' => match (true) {
                    $r->actor_type === 'admin' => ['name' => __('chat.audit_admin'), 'is_admin' => true],
                    $r->actor_type !== null => $directory->profile($me, $r->actor_type, $r->actor_id),
                    default => null,
                },
                'subject' => $this->subject($me, $directory, $r->subject),
                'before' => $r->before,
                'after' => $r->after,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->all(),
            'has_more' => $more,
        ];
    }

    /** "participant:12" → that member's name (as I see it); anything else as it is. */
    private function subject(Model $me, ParticipantDirectory $directory, ?string $subject): ?string
    {
        if ($subject !== null && str_starts_with($subject, 'participant:')) {
            $row = ChatParticipant::query()->find((int) substr($subject, 12));

            return $row ? ($directory->profile($me, $row->participant_type, $row->participant_id)['name'] ?? null) : null;
        }

        return null;
    }

    private function assertKind(ChatConversation $conversation): void
    {
        if (! GroupLifecycleService::isKindGroup($conversation)) {
            throw new ChatException('group_kind_required', 422);
        }
    }

    private function assertMay(Model $me, ChatConversation $conversation, string $permission): void
    {
        $this->conversations->participantOf($me, $conversation, true);

        if (! GroupPermissions::allows($me, $conversation, $permission)) {
            throw new ChatException('group_permission_denied', 403);
        }
    }
}
