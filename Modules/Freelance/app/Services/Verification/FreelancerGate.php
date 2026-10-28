<?php

namespace Modules\Freelance\Services\Verification;

use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Account\FreelancerAvailabilityService;

/**
 * The verification gate (FL-110): a freelancer signs in with their phone and moves around freely, but only a verified one
 * may apply, accept an invitation, start work or receive money. Enforced on the server (`freelance.verified` middleware,
 * or assertCanApply() inside a service) — the apps only show what this says.
 *
 * `step` names what the freelancer has to do next: complete_profile, submit_verification, under_review, fix_documents,
 * or that the account is suspended / blocked.
 */
class FreelancerGate
{
    public function step(Freelancer $freelancer): ?string
    {
        return match ($freelancer->status) {
            FreelancerStatus::Verified => null,
            FreelancerStatus::ProfileReady, FreelancerStatus::VerificationRejected => 'submit_verification',
            FreelancerStatus::VerificationSubmitted => 'under_review',
            FreelancerStatus::ChangesRequested => 'fix_documents',
            FreelancerStatus::Suspended => 'suspended',
            FreelancerStatus::Blocked => 'blocked',
            default => 'complete_profile',
        };
    }

    public function canApply(Freelancer $freelancer): bool
    {
        return $this->step($freelancer) === null;
    }

    /** @throws FreelanceException freelance_verification_required (403) with `data.step` */
    public function assertCanApply(Freelancer $freelancer): void
    {
        $step = $this->step($freelancer);
        if ($step !== null) {
            throw new FreelanceException('verification_required', 403, ['step' => __('freelance.steps.'.$step)], ['step' => $step]);
        }
    }

    /**
     * Applying with an offer (FL-301) needs a verified account AND a freelancer who is taking work now: not paused, not on time off (FL-106).
     *
     * @throws FreelanceException freelance_verification_required (403) or freelance_unavailable (422)
     */
    public function assertCanOffer(Freelancer $freelancer): void
    {
        $this->assertCanApply($freelancer);
        app(FreelancerAvailabilityService::class)->assertCanReceiveOffers($freelancer);
    }

    /** @return array{can_apply: bool, step: ?string, blocked_reasons: list<string>} what every account response carries */
    public function summary(Freelancer $freelancer): array
    {
        $step = $this->step($freelancer);

        return ['can_apply' => $step === null, 'step' => $step, 'blocked_reasons' => $step === null ? [] : [$step]];
    }
}
