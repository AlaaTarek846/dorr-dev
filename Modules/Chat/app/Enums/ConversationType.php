<?php

namespace Modules\Chat\Enums;

enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';
    /** Broadcast: anyone can follow a public one and read it, only its admins post. */
    case Channel = 'channel';
}
