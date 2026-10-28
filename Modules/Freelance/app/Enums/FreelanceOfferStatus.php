<?php

namespace Modules\Freelance\Enums;

/**
 * Where an offer stands (FL-301 / FL-303). It starts `submitted`; the client looking at it makes it `viewed`, and later
 * `shortlisted`, `rejected` or `accepted` (FL-305 / FL-309); the freelancer can take it back (`withdrawn`, FL-302).
 */
enum FreelanceOfferStatus: string
{
    case Submitted = 'submitted';
    case Viewed = 'viewed';
    case Shortlisted = 'shortlisted';
    case Rejected = 'rejected';
    case Accepted = 'accepted';
    case Withdrawn = 'withdrawn';

    /** @return list<self> where the client can take an offer from here (the freelancer's own withdrawal is separate) */
    public function next(): array
    {
        return match ($this) {
            self::Submitted => [self::Viewed, self::Shortlisted, self::Rejected],
            self::Viewed => [self::Shortlisted, self::Rejected, self::Accepted],
            self::Shortlisted => [self::Viewed, self::Rejected, self::Accepted],
            default => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** The freelancer may still change or take back the offer: before the client has accepted or turned it down. */
    public function editable(): bool
    {
        return in_array($this, [self::Submitted, self::Viewed, self::Shortlisted], true);
    }

    /** Still in the running: counts towards the project's limit of offers. */
    public function live(): bool
    {
        return in_array($this, [self::Submitted, self::Viewed, self::Shortlisted, self::Accepted], true);
    }
}
