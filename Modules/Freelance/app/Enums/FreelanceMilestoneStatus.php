<?php

namespace Modules\Freelance\Enums;

/**
 * Where a part of the work stands (FL-403):
 *
 *   unfunded → funded → in_progress → delivered → approved
 *                                        ↓   ↑
 *                                      revision
 *   any open part → disputed (FL-704) → approved / revision / cancelled (the staff decide)
 *   unfunded / funded / in_progress → cancelled
 *
 * Funding is FL-501 and paying out on approval FL-503; the moves are all here.
 */
enum FreelanceMilestoneStatus: string
{
    case Unfunded = 'unfunded';
    case Funded = 'funded';
    case InProgress = 'in_progress';
    case Delivered = 'delivered';
    case Revision = 'revision';
    case Approved = 'approved';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Unfunded => [self::Funded, self::Cancelled],
            self::Funded => [self::InProgress, self::Disputed, self::Cancelled],
            self::InProgress => [self::Delivered, self::Disputed, self::Cancelled],
            self::Delivered => [self::Approved, self::Revision, self::Disputed],
            self::Revision => [self::Delivered, self::Disputed, self::Cancelled],
            self::Disputed => [self::Approved, self::Revision, self::Cancelled],
            self::Approved, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Nothing more happens to it. */
    public function settled(): bool
    {
        return in_array($this, [self::Approved, self::Cancelled], true);
    }
}
