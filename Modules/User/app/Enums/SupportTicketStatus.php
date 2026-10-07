<?php

namespace Modules\User\Enums;

/**
 * Where a support ticket stands. Opened and reopened tickets take replies; resolved and closed
 * ones are finished until the customer (or support) reopens them.
 */
enum SupportTicketStatus: string
{
    case Opened = 'opened';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Reopened = 'reopened';

    public function acceptsReplies(): bool
    {
        return $this === self::Opened || $this === self::Reopened;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
