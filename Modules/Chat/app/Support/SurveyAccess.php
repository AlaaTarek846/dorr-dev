<?php

namespace Modules\Chat\Support;

use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;

/**
 * A survey (docs/groups_tasks.md GR-030) is a poll whose results and voters only its maker and the
 * group's managers (`reports.view`, `lessons.manage`) see; everyone else just votes.
 */
final class SurveyAccess
{
    public static function isSurvey(ChatMessage $message): bool
    {
        return (bool) data_get($message->meta, 'survey');
    }

    public static function canSeeResults(?ChatParticipant $participant, ChatConversation $conversation, ChatMessage $message): bool
    {
        if (! self::isSurvey($message)) {
            return true;
        }
        if ($participant === null) {
            return false;
        }
        if ($participant->participant_type === $message->sender_type && (int) $participant->participant_id === (int) $message->sender_id) {
            return true;
        }
        $perms = GroupPermissions::ofParticipant($participant, $conversation);

        return in_array('reports.view', $perms, true) || in_array('lessons.manage', $perms, true);
    }
}
