<?php

namespace Modules\AI\Services;

use Illuminate\Support\Facades\Log;
use Modules\AI\Models\AiDataPolicy;
use Throwable;

/**
 * What the active "personal" data policy (AiDataPolicy::activePersonal(), the newest switched-on one, not in the
 * trash) allows the AI engine to do with a person's data.
 *
 * - consent_required on: nothing records a person's consent to share their account data with the AI, so the
 *   personal details the system adds by itself (the profile: name, e-mail, phone, gender) are not sent. What the
 *   person types is theirs to send.
 * - external_provider_allowed off: every AI provider is external, so the profile is not sent either, and e-mails,
 *   phone numbers and id numbers are masked in the person's and the assistant's turns before any call leaves
 *   (AiGateway::applyDataRules, on top of the provider's own data rule).
 *
 * No policy = both allowed (the defaults). Fail-safe: if the policy cannot be read, the strict answer is used
 * (no profile, masking on) and a warning is logged - chat keeps working.
 */
class AiPersonalDataPolicy
{
    /**
     * @return array{policy: ?AiDataPolicy, failed: bool}
     */
    protected function read(): array
    {
        try {
            return ['policy' => AiDataPolicy::activePersonal(), 'failed' => false];
        } catch (Throwable $e) {
            Log::warning('AI data policy could not be read; applying the strict defaults', ['error' => $e->getMessage()]);

            return ['policy' => null, 'failed' => true];
        }
    }

    /** Whether the person's own profile details may be added to the prompt. */
    public function allowsOwnerProfile(): bool
    {
        ['policy' => $policy, 'failed' => $failed] = $this->read();

        if ($failed) {
            return false;
        }

        return $policy === null || (! $policy->consent_required && $policy->external_provider_allowed);
    }

    /** Whether personal data must be masked in the conversation turns before they leave to a provider. */
    public function mustMaskOutgoingPersonalData(): bool
    {
        ['policy' => $policy, 'failed' => $failed] = $this->read();

        return $failed || ($policy !== null && ! $policy->external_provider_allowed);
    }
}
