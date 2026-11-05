<?php

namespace Modules\Moving\Services\Payment;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Models\MovingCase;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Services\Requests\MovingJournal;
use Modules\User\Models\User;

/**
 * After delivery (B-11, B-14, owner's calls §8 and the requirements' REQ-049, REQ-052): the money goes to the
 * establishment, less Dorr's commission, when the customer confirms or when the objection window ends. A report made
 * in the window keeps only the claimed part held (capped by the country's share and the establishment's net); the
 * rest goes on time. A person decides the report, never the system (REQ-006): the award goes back to the customer,
 * the rest of the held part to the establishment.
 */
class MovingSettlement
{
    public function __construct(
        private readonly MovingMoney $money,
        private readonly MovingJournal $journal,
    ) {}

    public function confirmReceipt(User $user, MovingRequest $request): MovingRequest
    {
        if ($request->user_id !== $user->id || $request->status !== MovingStatus::Delivered) {
            throw new MovingException('request_not_found', 404);
        }
        $this->journal->move($request, null, 'receipt_confirmed', 'user', $user->id);

        return $this->settle($request);
    }

    /**
     * @param  list<UploadedFile>  $photos
     */
    public function report(User $user, MovingRequest $request, string $reason, ?string $details, int $claimMinor, array $photos): MovingCase
    {
        if ($request->user_id !== $user->id || $request->status !== MovingStatus::Delivered) {
            throw new MovingException('report_closed', 409);
        }
        if ($request->objection_until !== null && $request->objection_until->isPast()) {
            throw new MovingException('report_closed', 409);
        }
        if ($request->cases()->where('kind', MovingCase::REPORT)->where('status', 'open')->exists()) {
            throw new MovingException('report_exists', 409);
        }

        return DB::transaction(function () use ($user, $request, $reason, $details, $claimMinor, $photos): MovingCase {
            $case = $request->cases()->create([
                'kind' => MovingCase::REPORT, 'opened_by' => 'user', 'opener_id' => $user->id,
                'reason_code' => $reason, 'details' => $details, 'claim_minor' => $claimMinor, 'status' => 'open',
            ]);
            foreach ($photos as $photo) {
                $case->addMedia($photo)->toMediaCollection('evidence');
            }
            $this->journal->move($request, null, 'reported', 'user', $user->id, [], ['case' => $case->uuid, 'claim_minor' => $claimMinor]);

            return $case;
        });
    }

    /** Pays the establishment; an open report keeps its part held and the job waits for the decision. */
    public function settle(MovingRequest $request): MovingRequest
    {
        return DB::transaction(function () use ($request): MovingRequest {
            /** @var MovingRequest $request */
            $request = MovingRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->settled_at !== null || $request->status !== MovingStatus::Delivered) {
                return $request;
            }
            $report = $request->cases()->where('kind', MovingCase::REPORT)->where('status', 'open')->first();
            $held = $report ? $this->claimHold($request, (int) $report->claim_minor) : 0;

            $total = (int) $request->total_minor;
            $taken = $this->money->take($request, $total - $held, 'settle');
            $this->money->holdForClaim($request, $held);
            $paid = $this->money->payEstablishment($request, (int) $request->provider_net_minor - $held, 'settle');
            $this->money->book($request, $taken ?? $paid);
            $report?->update(['held_minor' => $held]);

            return $this->journal->move($request, $report ? MovingStatus::Disputed : MovingStatus::Completed, 'settled', 'system', null, [
                'settled_at' => now(),
                'payment_status' => $report ? 'partly_held' : 'settled',
            ], ['paid_minor' => (int) $request->provider_net_minor - $held, 'held_minor' => $held]);
        });
    }

    /** Settles every delivered job whose objection window ended. */
    public function settleDue(): int
    {
        $count = 0;
        MovingRequest::query()->where('status', MovingStatus::Delivered->value)->whereNull('settled_at')
            ->whereNotNull('objection_until')->where('objection_until', '<=', now())
            ->chunkById(100, function ($requests) use (&$count): void {
                foreach ($requests as $request) {
                    $this->settle($request);
                    $count++;
                }
            });

        return $count;
    }

    /** The admin's decision on a report: `$awardMinor` back to the customer, the rest of the held part to the establishment. */
    public function decideReport(MovingCase $case, int $awardMinor, ?string $note, int $adminId): MovingCase
    {
        if ($case->kind !== MovingCase::REPORT || ! $case->isOpen()) {
            throw new MovingException('case_closed', 409);
        }

        return DB::transaction(function () use ($case, $awardMinor, $note, $adminId): MovingCase {
            $request = MovingRequest::query()->whereKey($case->request_id)->lockForUpdate()->firstOrFail();
            if ($request->status !== MovingStatus::Disputed) {
                throw new MovingException('case_not_ready', 409);
            }
            $held = (int) $case->held_minor;
            $award = min(max(0, $awardMinor), $held);
            $this->money->take($request, $held - $award, 'claim');
            $this->money->payEstablishment($request, $held - $award, 'claim');

            $case->update([
                'status' => 'decided',
                'decision' => $award === 0 ? 'rejected' : ($award === $held ? 'accepted' : 'partial'),
                'award_minor' => $award,
                'decision_note' => $note,
                'decided_by_admin_id' => $adminId,
                'decided_at' => now(),
            ]);
            $this->journal->move($request, MovingStatus::Completed, 'report_decided', 'admin', $adminId, [
                'payment_status' => 'settled',
                'refunded_minor' => ((int) $request->refunded_minor) + $award,
            ], ['case' => $case->uuid, 'award_minor' => $award]);

            return $case;
        });
    }

    /** What a report may keep held: the claim, at most the country's share of the job, never more than the establishment's net. */
    private function claimHold(MovingRequest $request, int $claim): int
    {
        $cap = (int) $request->provider_net_minor;
        $share = $request->policy('dispute.max_hold_bp');
        if ($share !== null) {
            $cap = min($cap, intdiv((int) $request->total_minor * (int) $share, 10000));
        }

        return max(0, min($claim, $cap));
    }
}
