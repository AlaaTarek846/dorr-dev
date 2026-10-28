<?php

namespace Modules\AI\Services\Ar;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Exceptions\AiArException;
use Modules\AI\Models\Ar\AiArApproval;
use Modules\AI\Models\Ar\AiArExperience;
use Modules\Provider\Models\Provider;

/**
 * The review loop between a customer and a provider (a decorator, a furniture seller...).
 *   customer: submit (pins the current version)  ->  provider: approve | request changes | reject
 *   customer: accept an approved version (locks the design) or withdraw / edit and resubmit.
 * While a version is under review or accepted, the customer cannot change the design, so what the provider
 * approved is exactly what was accepted. Editing an approved-but-not-accepted design drops the approval.
 */
class AiArApprovalService
{
    public function __construct(protected AiArNotifier $notifier) {}

    public function submit(Authenticatable $owner, AiArExperience $experience, int $providerId, ?string $note = null): AiArApproval
    {
        if ($experience->current_version_id === null || ! $experience->currentVersion()->first()?->items()->exists()) {
            throw new AiArException('nothing_to_submit', 422);
        }

        if (in_array($experience->status, ['submitted', 'accepted'], true)) {
            throw new AiArException('experience_locked', 409);
        }

        if (Provider::query()->whereKey($providerId)->doesntExist()) {
            throw new AiArException('provider_invalid', 422);
        }

        $approval = DB::transaction(function () use ($experience, $providerId, $note) {
            $approval = AiArApproval::query()->create([
                'experience_id' => $experience->id, 'experience_version_id' => $experience->current_version_id,
                'provider_id' => $providerId, 'status' => AiArApproval::PENDING, 'customer_note' => $note,
            ]);
            $experience->forceFill(['status' => 'submitted'])->save();

            return $approval;
        });

        $this->notifier->submitted($approval->setRelation('experience', $experience));

        return $approval;
    }

    public function withdraw(AiArApproval $approval): AiArApproval
    {
        $this->mustBePending($approval);
        $approval->forceFill(['status' => AiArApproval::WITHDRAWN])->save();
        $approval->experience->forceFill(['status' => 'active'])->save();
        $this->notifier->withdrawn($approval);

        return $approval;
    }

    public function decide(Authenticatable $provider, AiArApproval $approval, string $decision, ?string $note = null, ?int $quoteMinor = null): AiArApproval
    {
        if ($approval->provider_id !== (int) $provider->getAuthIdentifier()) {
            throw new AiArException('not_found', 404);
        }

        $this->mustBePending($approval);

        $status = ['approve' => AiArApproval::APPROVED, 'request_changes' => AiArApproval::CHANGES_REQUESTED, 'reject' => AiArApproval::REJECTED][$decision] ?? throw new AiArException('decision_invalid', 422);

        if ($status !== AiArApproval::APPROVED && blank($note)) {
            throw new AiArException('note_required', 422);
        }

        // A price only makes sense on an approval; a currency comes from the customer's country, never from the client.
        $quote = $status === AiArApproval::APPROVED ? $quoteMinor : null;
        $currency = $quote === null ? null : (\App\Models\Country::query()->with('currency')->find($approval->experience?->country_id)?->currency?->code ?? \App\Models\Country::query()->with('currency')->where('is_default', true)->first()?->currency?->code);

        $approval->forceFill(['status' => $status, 'provider_note' => $note, 'quote_minor' => $quote, 'currency' => $currency, 'decided_at' => now()])->save();
        $approval->experience->forceFill(['status' => $status === AiArApproval::APPROVED ? 'approved' : 'active'])->save();
        $this->notifier->decided($approval);

        return $approval;
    }

    public function accept(AiArExperience $experience): AiArExperience
    {
        $approved = AiArApproval::query()->where('experience_id', $experience->id)->where('status', AiArApproval::APPROVED)
            ->where('experience_version_id', $experience->current_version_id)->latest('id')->first();

        if ($experience->status !== 'approved' || $approved === null) {
            throw new AiArException('not_approved', 409);
        }

        // A priced design is accepted by paying for it (AiArOrderService::acceptAndPay), never for free.
        if (($approved->quote_minor ?? 0) > 0) {
            throw new AiArException('payment_required', 409);
        }

        $approved->forceFill(['accepted_at' => now()])->save();
        $experience->forceFill(['status' => 'accepted'])->save();
        $this->notifier->accepted($approved->setRelation('experience', $experience));

        return $experience;
    }

    protected function mustBePending(AiArApproval $approval): void
    {
        if ($approval->status !== AiArApproval::PENDING) {
            throw new AiArException('approval_closed', 409);
        }
    }
}
