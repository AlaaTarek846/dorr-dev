<?php

namespace Modules\Chat\Support;

use Illuminate\Support\Collection;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupMemberRole;
use Modules\Chat\Models\ChatGroupRole;
use Modules\Chat\Models\ChatGroupSpace;
use Modules\Chat\Models\ChatParticipant;

/**
 * The students of an educational group — members holding a student's role (hands in homework,
 * doesn't set it), never the owner — in the whole group or in one term / subgroup (as main-group
 * participants).
 */
final class GroupStudents
{
    /** @return Collection<int, ChatParticipant> */
    public static function of(ChatConversation $conversation, ?int $spaceId = null): Collection
    {
        // A student's role hands in homework without setting it (the teacher's role does both).
        $roles = ChatGroupRole::query()->where('conversation_id', $conversation->id)->get()
            ->filter(fn (ChatGroupRole $r) => in_array('assignments.submit', (array) $r->permissions, true) && ! in_array('assignments.manage', (array) $r->permissions, true))
            ->pluck('id');
        $holders = ChatGroupMemberRole::query()->whereIn('role_id', $roles)->pluck('participant_id');
        $students = $conversation->activeParticipants()->whereIn('id', $holders)
            ->where('role', '!=', ParticipantRole::Owner->value)->get();

        return $spaceId === null ? $students : self::inSpace($students, $spaceId);
    }

    /** Everyone of the main group concerned by an item: the whole group, or that space's members. */
    public static function audience(ChatConversation $conversation, ?int $spaceId): Collection
    {
        $members = $conversation->activeParticipants()->get();

        return $spaceId === null ? $members : self::inSpace($members, $spaceId);
    }

    /**
     * @param  Collection<int, ChatParticipant>  $rows  main-group participants
     * @return Collection<int, ChatParticipant>
     */
    private static function inSpace(Collection $rows, int $spaceId): Collection
    {
        $space = ChatGroupSpace::query()->find($spaceId);
        if ($space === null) {
            return collect();
        }
        $keys = ChatParticipant::query()->where('conversation_id', $space->conversation_id)->whereNull('left_at')->get(['participant_type', 'participant_id'])
            ->map(fn ($p) => $p->participant_type.':'.$p->participant_id)->all();

        return $rows->filter(fn (ChatParticipant $p) => in_array($p->participant_type.':'.$p->participant_id, $keys, true))->values();
    }
}
