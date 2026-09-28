<?php

namespace Modules\Chat\Enums;

/**
 * Message requests (direct chats only): a stranger's first message opens a `pending` chat that
 * the recipient accepts or rejects. Groups are always `accepted`.
 */
enum ConversationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
