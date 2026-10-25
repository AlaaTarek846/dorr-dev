<?php

namespace Modules\Freelance\Services\Projects;

use Modules\Freelance\Models\FreelanceProjectApproval;
use App\Models\Country;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as Status;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Services\Chat\OfferChat;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Policy\FreelanceLedger;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;

/**
 * Paying a part out when it is approved (FL-503), approving it by itself when the client doesn't answer in time (FL-504), and
 * closing the project once every part is settled (FL-408).
 *
 * Approving is one transaction: the part moves to `approved`, the client's holds for it (amount + client fee) are captured, the
 * freelancer's wallet is credited the net (amount − commission, withdrawable — FL-505 adds a waiting time), and the client fee and
 * the commission are booked as income in the ledger. The numbers are the part's own, worked out from the policy frozen in the
 * agreement. If anything fails, nothing happened: the part stays delivered. Every wallet movement has a key of its own
 * (`freelance:milestone:{uuid}:…`), so a repeat can't pay twice.
 */
class MilestoneRelease
{
    /** The client is reminded this long before an unanswered delivery approves itself. */
    public const REMIND_HOURS = 24;

    public function __construct(
        private readonly MilestoneWork $work,
        private readonly MilestonePayments $payments,
        private readonly WalletService $wallets,
        private readonly FreelanceLedger $ledger,
        private readonly FreelanceNotifier $notifier,
        private readonly ProjectStateMachine $machine,
        private readonly OfferChat $chat,
    ) {}

    /** `$actorType` = client (their approval) or system (the review time ran out). */
    public function approve(Milestone $milestone, string $actorType, ?int $actorId): Milestone
    {
        $agreement = $milestone->agreement()->with(['project', 'client', 'freelancer'])->firstOrFail();

        $approved = DB::transaction(function () use ($milestone, $agreement, $actorType, $actorId) {
            $m = $this->work->move($milestone->setRelation('agreement', $agreement), Status::Approved, $actorType === 'system' ? 'milestone.auto_approved' : 'milestone.approved',
                $actorType, $actorId, ['approved_at' => now(), 'cancel_requested_by' => null, 'cancel_requested_at' => null, 'cancel_reason' => null]);
            $earning = $this->payOut($m, $agreement);
            // FL-511: a receipt for each side, with the movement.
            $reason = match ($actorType) { 'system' => 'auto_approved', 'admin' => 'dispute', default => 'approved' };
            $receipts = app(FreelanceReceipts::class);
            $receipts->issue($m, $agreement, 'client', 'paid', $reason, ['amount' => $m->amount_minor, 'fee' => $m->client_fee_minor, 'total' => $m->amount_minor + $m->client_fee_minor]);
            $receipts->issue($m, $agreement, 'freelancer', 'earned', $reason, ['amount' => $m->amount_minor, 'fee' => $m->commission_minor, 'total' => $m->net_minor]);

            return [$m, $earning];
        });
        [$approved, $earning] = $approved;

        $project = $agreement->project;
        $vars = ['project' => (string) $project->title];
        $data = ['project' => $project->uuid, 'milestone' => $approved->uuid];
        $this->notifier->notify($agreement->freelancer, 'milestone.approved', $vars, $data);
        $amount = ['amount' => self::money((int) $approved->paid_minor, $agreement->currency_code)];
        // FL-505: in the wallet now, or on its day when the agreement's policy holds earnings.
        if ($earning?->status === \Modules\Freelance\Models\FreelanceEarning::PENDING) {
            $this->notifier->notify($agreement->freelancer, 'payment.pending', $vars + $amount + ['date' => $earning->release_at->toDateString()], $data);
        } else {
            $this->notifier->notify($agreement->freelancer, 'payment.released', $vars + $amount, $data);
        }
        if ($actorType === 'system') {
            $this->notifier->notify($agreement->client, 'milestone.auto_approved', $vars, $data);
        }
        $this->closeIfSettled($agreement);

        return $approved;
    }

    /** Hourly (FL-504): reminds the client a day before, then approves the deliveries left unanswered past the review days. */
    public function releaseDue(): int
    {
        $approved = 0;
        Milestone::query()->where('status', Status::Delivered->value)->whereNotNull('delivered_at')
            ->whereHas('agreement', fn ($q) => $q->where('status', Agreement::ACTIVE))->with('agreement.project', 'agreement.client')
            ->each(function (Milestone $m) use (&$approved): void {
                $due = $this->dueAt($m);
                if ($due === null) {
                    return;
                }
                if ($due->isPast()) {
                    try {
                        $this->approve($m, 'system', null);
                        $approved++;
                    } catch (FreelanceException $e) {
                        // Moved meanwhile, or the money isn't there: left for the staff (the log says which).
                        logger()->warning('[Freelance] auto-approve skipped', ['milestone' => $m->uuid, 'error' => $e->apiErrorCode()]);
                    }

                    return;
                }
                if ($m->review_reminded_at === null && $due->copy()->subHours(self::REMIND_HOURS)->isPast()) {
                    $m->forceFill(['review_reminded_at' => now()])->save();
                    $this->notifier->notify($m->agreement->client, 'milestone.review_due', [
                        'project' => (string) $m->agreement->project->title, 'date' => $due->toDateString(),
                    ], ['project' => $m->agreement->project->uuid, 'milestone' => $m->uuid]);
                }
            });

        return $approved;
    }

    /** When an unanswered delivery approves itself, from the agreement's frozen policy (null: it doesn't). */
    public function dueAt(Milestone $m): ?\Illuminate\Support\Carbon
    {
        $policy = (array) $m->agreement->policy;
        if ($m->status !== Status::Delivered || $m->delivered_at === null || ! filter_var($policy['review.auto_approve'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return $m->delivered_at->copy()->addDays(max(1, (int) ($policy['review.days'] ?? 7)));
    }

    /** FL-408: every part approved or cancelled — the agreement is completed and the project closed (once). */
    public function closeIfSettled(Agreement $agreement): void
    {
        $closed = DB::transaction(function () use ($agreement) {
            $a = Agreement::query()->whereKey($agreement->id)->lockForUpdate()->with('project')->firstOrFail();
            if ($a->status !== Agreement::ACTIVE || ! $this->work->allSettled($a)) {
                return null;
            }
            $a->fill(['status' => Agreement::COMPLETED, 'completed_at' => now()])->save();
            if ($a->project->status === FreelanceProjectStatus::InProgress) {
                $this->machine->move($a->project, FreelanceProjectStatus::Closed, 'project.completed', 'system', null, ['closed_at' => now()], ['agreement' => $a->uuid]);
            }
            FreelanceProjectApproval::forAgreement($a, 'agreement.completed', 'system', null);
            $this->chat->freeze($a->project, (int) $a->freelancer_id);

            return $a;
        });
        if ($closed !== null) {
            $vars = ['project' => (string) $closed->project->title];
            $data = ['project' => $closed->project->uuid];
            $this->notifier->notify($closed->client, 'project.completed', $vars, $data);
            $this->notifier->notify($closed->freelancer, 'project.completed', $vars, $data);
        }
    }

    /** Inside the approving transaction. */
    private function payOut(Milestone $m, Agreement $a): ?\Modules\Freelance\Models\FreelanceEarning
    {
        $needed = $m->amount_minor + $m->client_fee_minor;
        $holds = $this->payments->activeHolds($m);
        if ((int) $holds->sum('amount_minor') !== $needed) {
            throw new FreelanceException('milestone_not_funded', 409);
        }

        $project = $a->project;
        $notes = ['key' => 'wallet.notes.freelance_payment', 'variables' => ['project' => (string) $project->title]];
        $capture = null;
        foreach ($holds as $hold) {
            $capture = $this->wallets->capture($hold, (int) $hold->amount_minor, WalletTransactionType::ServicePayment, [
                'reference_type' => MilestonePayments::REFERENCE, 'reference_id' => $m->id, 'notes' => $notes,
                'idempotency_key' => "freelance:milestone:{$m->uuid}:pay:{$hold->id}",
            ]);
        }

        $country = Country::query()->with('currency')->findOrFail($project->country_id);
        // FL-505: the net goes to the wallet now, or waits its days.
        $earning = app(FreelanceEarnings::class)->credit($m, $a, $country, $m->net_minor, 'approved', "freelance:milestone:{$m->uuid}:earning");
        $earningTx = $earning?->wallet_transaction_id === null ? null : \Modules\Wallet\Models\WalletTransaction::query()->find($earning->wallet_transaction_id);

        // What DORR keeps: the client fee and the commission, as income.
        $this->ledger->clientFee($m->client_fee_minor, $country->currency, $country, $m, (string) $project->title, $capture);
        $this->ledger->commission($m->commission_minor, $country->currency, $country, $m, (string) $project->title, $earningTx);

        $m->forceFill(['released_at' => now(), 'paid_minor' => $m->net_minor])->save();

        return $earning;
    }

    private static function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2).' '.$currency;
    }
}
