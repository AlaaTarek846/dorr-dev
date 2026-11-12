<?php

namespace Modules\Chat\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupSpace;
use Modules\Chat\Models\ChatParticipant;

/**
 * Who sees what in an educational / work group: the whole group's items to its members, a term's
 * or subgroup's items to that space's members — and to whoever manages that kind of item.
 */
final class GroupScope
{
    public static function member(Model $viewer, int $conversationId): ?ChatParticipant
    {
        return ChatParticipant::query()->where('conversation_id', $conversationId)->of($viewer)->whereNull('left_at')->first();
    }

    /** @return list<int> the spaces of this group the viewer is in */
    public static function spaceIds(Model $viewer, int $conversationId): array
    {
        $mineIn = ChatParticipant::query()->of($viewer)->whereNull('left_at')->select('conversation_id');

        return ChatGroupSpace::query()->where('parent_conversation_id', $conversationId)->whereIn('conversation_id', $mineIn)->pluck('id')->all();
    }

    /** A member, and the item is for the whole group, or for a space they're in, or they manage it. */
    public static function canSee(Model $viewer, int $conversationId, ?int $spaceId, ?string $managePermission = null): bool
    {
        $member = self::member($viewer, $conversationId);
        if ($member === null) {
            return false;
        }
        if ($spaceId === null || in_array($spaceId, self::spaceIds($viewer, $conversationId), true)) {
            return true;
        }
        $conversation = ChatConversation::query()->find($conversationId);

        return $managePermission !== null && $conversation !== null
            && in_array($managePermission, GroupPermissions::ofParticipant($member, $conversation), true);
    }

    /** A space of this group, given by id (null for the whole group). */
    public static function space(ChatConversation $conversation, mixed $spaceId): ?ChatGroupSpace
    {
        if (blank($spaceId)) {
            return null;
        }

        return ChatGroupSpace::query()->where('parent_conversation_id', $conversation->id)->find((int) $spaceId)
            ?? throw new \Modules\Chat\Exceptions\ChatException('space_not_found', 404);
    }
}
