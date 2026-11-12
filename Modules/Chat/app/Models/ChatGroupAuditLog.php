<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Support\ParticipantType;

/**
 * The record of sensitive actions in an educational or work group (docs/groups_tasks.md GR-010):
 * who did what, when, and the value before and after. Written, never edited.
 */
class ChatGroupAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'chat_group_audit_log';

    protected $fillable = ['conversation_id', 'actor_type', 'actor_id', 'action', 'subject', 'before', 'after'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    /** Only in educational / work groups and their terms / projects (ordinary groups keep no log). */
    public static function recordIfKept(ChatConversation $conversation, ?Model $actor, string $action, ?string $subject = null, ?array $before = null, ?array $after = null): ?self
    {
        $group = $conversation->group;
        $kept = $group !== null && (($group->kind ?? \Modules\Chat\Enums\GroupKind::Normal) !== \Modules\Chat\Enums\GroupKind::Normal || $group->space_of_conversation_id !== null);

        return $kept ? self::record($conversation, $actor, $action, $subject, $before, $after) : null;
    }

    public static function record(ChatConversation $conversation, ?Model $actor, string $action, ?string $subject = null, ?array $before = null, ?array $after = null): self
    {
        return self::query()->create([
            'conversation_id' => $conversation->id,
            'actor_type' => $actor ? ParticipantType::aliasFor($actor) : null,
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject' => $subject,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
