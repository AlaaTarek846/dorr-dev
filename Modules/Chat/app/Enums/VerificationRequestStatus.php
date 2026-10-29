<?php

namespace Modules\Chat\Enums;

/**
 * Where a verification request stands (REQ-01-06). The moves allowed are written once, here
 * (transitions()); anything else is refused. The request's status is separate from the badge's
 * and the subscription's (REQ-02-01).
 *
 *   new ─▶ under_review ─▶ needs_completion ─▶ under_review …
 *                       ─▶ preliminary_approved ─▶ in_execution ─▶ awaiting_evidence ─▶ final_review ─▶ completed
 *                       ─▶ rejected
 *   any open status ─▶ suspended (its deadline passed, or a hold) ─▶ back where it was / needs_completion / expired
 *   any open status ─▶ cancelled
 */
enum VerificationRequestStatus: string
{
    case New = 'new';
    case UnderReview = 'under_review';
    case NeedsCompletion = 'needs_completion';
    case PreliminaryApproved = 'preliminary_approved';
    case InExecution = 'in_execution';
    case AwaitingEvidence = 'awaiting_evidence';
    case FinalReview = 'final_review';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Suspended = 'suspended';

    /** Closed: the account may send a new request (REQ-01-03). */
    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Cancelled, self::Expired], true);
    }

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::New => [self::UnderReview, self::NeedsCompletion, self::Rejected, self::Cancelled, self::Suspended],
            self::UnderReview => [self::NeedsCompletion, self::PreliminaryApproved, self::Rejected, self::Cancelled, self::Suspended],
            self::NeedsCompletion => [self::UnderReview, self::Rejected, self::Cancelled, self::Suspended],
            self::PreliminaryApproved => [self::InExecution, self::Completed, self::Rejected, self::Cancelled, self::Suspended],
            self::InExecution => [self::AwaitingEvidence, self::FinalReview, self::Cancelled, self::Suspended],
            self::AwaitingEvidence => [self::FinalReview, self::Cancelled, self::Suspended],
            self::FinalReview => [self::Completed, self::Rejected, self::AwaitingEvidence, self::Suspended],
            self::Suspended => [self::New, self::UnderReview, self::NeedsCompletion, self::PreliminaryApproved, self::InExecution, self::AwaitingEvidence, self::FinalReview, self::Cancelled, self::Expired],
            self::Completed, self::Rejected, self::Cancelled, self::Expired => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => ! $s->isClosed())));
    }
}
