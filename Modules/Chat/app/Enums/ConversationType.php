<?php

namespace Modules\Chat\Enums;

enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';
    // Admins post, followers read and react. Group-like (isGroup() is true), see ChannelService.
    case Channel = 'channel';
}
