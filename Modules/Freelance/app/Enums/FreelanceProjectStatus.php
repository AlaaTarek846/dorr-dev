<?php

namespace Modules\Freelance\Enums;

/**
 * Where a client's project stands (FL-202). Every project is reviewed before it is published (owner's decision):
 *
 *   draft → pending_review → published → awarded (FL-309: the client chose an offer; the agreement and the work come next)
 *                 ↓    ↑         ↘ closed / cancelled
 *     changes_requested / rejected
 *
 * A pending project can be withdrawn back to a draft to be edited; drafts are deleted, not cancelled.
 */
enum FreelanceProjectStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';
    case Published = 'published';
    case Awarded = 'awarded';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview],
            self::PendingReview => [self::Published, self::ChangesRequested, self::Rejected, self::Draft, self::Cancelled],
            // A rejected project is not final: the client reads the reason, edits it and sends it again.
            self::ChangesRequested, self::Rejected => [self::PendingReview, self::Cancelled],
            self::Published => [self::Awarded, self::Closed, self::Cancelled],
            // FL-401 adds the move to the agreement / the work; until then a chosen project can only be cancelled.
            self::Awarded => [self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** The client may change the project only before it is sent, or when the reviewer asked for changes. */
    public function editable(): bool
    {
        return in_array($this, [self::Draft, self::ChangesRequested, self::Rejected], true);
    }

    /** Still going: counts towards the client's limit of open projects. */
    public function open(): bool
    {
        return ! in_array($this, [self::Closed, self::Cancelled], true);
    }
}
