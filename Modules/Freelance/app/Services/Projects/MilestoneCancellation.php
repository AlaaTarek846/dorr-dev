<?php

namespace Modules\Freelance\Services\Projects;

use Modules\Freelance\Models\FreelanceProjectApproval;
use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Policy\FreelanceLedger;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;

/**
 * Ending a part of the work after its money was held (FL-506).
 *
 *  - Funded, nothing started: either side ends it and the client gets back everything held (amount and fee) — the holds are simply freed.
 *  - Started (in progress or being changed): ending it takes the other side's word, within a few days. When agreed, the freelancer is
 *    paid a share of the part and the rest goes back to the client. The share is the policy value `cancellation.started_freelancer_bp`
 *    (basis points of the part's amount, set from the dashboard per country / specialty; read from the policy frozen in the agreement),
 *    with the same share of the client fee and of the commission. Only what was used is captured from the holds; the rest is freed.
 *  - Delivered or in dispute: not here (approve, ask for changes, or the dispute — FL-704 / FL-507).
 */
class MilestoneCancellation
{
    /** The other side has this long to answer. */
    public const ANSWER_DAYS = 3;

    public function __construct(
        private readonly MilestoneWork $work,
        private readonly MilestonePayments $payments,
        private readonly MilestoneRelease $release,
        private readonly WalletService $wallets,
        private readonly FreelanceLedger $ledger,
        private readonly FreelanceNotifier $notifier,
    ) {}

    /** The client ends a part: before the work, at once; after it started, they ask. */
    public function byClient(Milestone $m, User $client, ?string $reason): Milestone
    {
        abort_unless((int) $m->agreement->client_id === (int) $client->id, 404);
        if ($m->status === Status::Unfunded) {
            return $this->work->cancel($m, $client);
        }

        return $this->end($m, 'client', $client->id, $reason);
    }

    /** The freelancer steps back from a part: before the work, at once; after it started, they ask. */
    public function byFreelancer(Milestone $m, Freelancer $freelancer, ?string $reason): Milestone
    {
        abort_unless((int) $m->agreement->freelancer_id === (int) $freelancer->id, 404);

        return $this->end($m, 'freelancer', $freelancer->id, $reason);
    }

    /** The other side agrees to end a started part. */
    public function accept(Milestone $m, Model $actor, string $side): Milestone
    {
        $this->assertParty($m, $actor, $side);

        // The request is checked again on the locked row, inside the settling transaction.
        return $this->settleStarted($m, $side, $actor->getKey());
    }

    /** The other side doesn't: the part goes on, and the one who asked is told. */
    public function reject(Milestone $m, Model $actor, string $side): Milestone
    {
        $this->assertParty($m, $actor, $side);
        $asked = DB::transaction(function () use ($m, $actor, $side) {
            // Locked, so an answer racing another answer (or the request being taken back) can't both win.
            $locked = $this->lock($m);
            $this->assertAnswerable($locked, $side);
            $asked = $locked->cancel_requested_by;
            $locked->forceFill(['cancel_requested_by' => null, 'cancel_requested_at' => null, 'cancel_reason' => null])->save();
            DB::table('freelance_milestone_events')->insert([
                'milestone_id' => $locked->id, 'type' => 'milestone.cancel_rejected', 'from_status' => $locked->status->value, 'to_status' => $locked->status->value,
                'actor_type' => $side, 'actor_id' => $actor->getKey(), 'payload' => null, 'created_at' => now(),
            ]);
            FreelanceProjectApproval::record((int) $locked->project_id, (int) $locked->agreement_id, 'milestone.cancel_rejected', $side, $actor->getKey(), (int) $locked->version,
                ['milestone' => $locked->uuid, 'title' => $locked->title]);

            return $asked;
        });
        $this->tell($m, $asked, 'milestone.cancel_rejected');

        return $m->refresh();
    }

    /** Frees every funded part of a project that is being cancelled (nothing started on them); the caller made sure nothing else is open. */
    public function refundFunded(\Modules\Freelance\Models\FreelanceProject $project, string $actorType, ?int $actorId): void
    {
        Milestone::query()->where('project_id', $project->id)->whereIn('status', [Status::Funded->value, Status::Unfunded->value])->with('agreement.project')->get()
            ->each(fn (Milestone $m) => $m->status === Status::Funded ? $this->refundAll($m, $actorType, $actorId) : $this->work->move($m, Status::Cancelled, 'milestone.cancelled', $actorType, $actorId));
    }

    /** Is there a request waiting for an answer (and still in time)? */
    public function open(Milestone $m): bool
    {
        return $m->cancel_requested_by !== null && $m->cancel_requested_at !== null && $m->cancel_requested_at->copy()->addDays(self::ANSWER_DAYS)->isFuture();
    }

    // ------------------------------------------------------------------ inside

    private function end(Milestone $m, string $side, ?int $actorId, ?string $reason): Milestone
    {
        $reason = $reason === null ? null : (trim(strip_tags($reason)) ?: null);
        if ($m->status === Status::Funded) {
            $done = $this->refundAll($m, $side, $actorId, $reason);
            $this->tell($done, $side === 'client' ? 'freelancer' : 'client', 'milestone.called_off');
            $this->release->closeIfSettled($m->agreement);

            return $done;
        }
        if (! in_array($m->status, [Status::InProgress, Status::Revision], true)) {
            throw new FreelanceException('milestone_invalid_state', 409, ['status' => __('freelance.milestone_statuses.'.$m->status->value)], ['status' => $m->status->value]);
        }
        if ($this->open($m)) {
            throw new FreelanceException('milestone_cancel_pending', 409);
        }
        $m->forceFill(['cancel_requested_by' => $side, 'cancel_requested_at' => now(), 'cancel_reason' => $reason])->save();
        DB::table('freelance_milestone_events')->insert([
            'milestone_id' => $m->id, 'type' => 'milestone.cancel_requested', 'from_status' => $m->status->value, 'to_status' => $m->status->value,
            'actor_type' => $side, 'actor_id' => $actorId, 'payload' => $reason === null ? null : json_encode(['reason' => $reason]), 'created_at' => now(),
        ]);
        FreelanceProjectApproval::record((int) $m->project_id, (int) $m->agreement_id, 'milestone.cancel_requested', $side, $actorId, (int) $m->version,
            ['milestone' => $m->uuid, 'title' => $m->title] + ($reason === null ? [] : ['reason' => $reason]));
        $this->tell($m, $side === 'client' ? 'freelancer' : 'client', 'milestone.cancel_requested');

        return $m->refresh();
    }

    /** Everything held goes back: the holds are freed. */
    public function refundAll(Milestone $m, string $actorType, ?int $actorId, ?string $reason = null): Milestone
    {
        return DB::transaction(function () use ($m, $actorType, $actorId, $reason) {
            $refund = $this->payments->held($m);
            $moved = $this->work->move($m, Status::Cancelled, 'milestone.cancelled', $actorType, $actorId, [
                'refunded_minor' => $refund, 'cancel_requested_by' => null, 'cancel_requested_at' => null, 'cancel_reason' => $reason,
            ], ['refunded_minor' => $refund] + ($reason === null ? [] : ['reason' => $reason]));
            foreach ($this->payments->activeHolds($m) as $hold) {
                $this->wallets->release($hold, 'freelance_cancelled');
            }
            // FL-511: the client's receipt of what came back.
            app(FreelanceReceipts::class)->issue($moved, $moved->agreement ?? $m->agreement, 'client', 'refunded', $actorType === 'admin' ? 'dispute' : 'cancelled', ['total' => $refund]);

            return $moved;
        });
    }

    private function settleStarted(Milestone $m, string $side, mixed $actorId): Milestone
    {
        return $this->settleShare($m, $this->shareBp($m->agreement), $side, (int) $actorId, fn (Milestone $locked) => $this->assertAnswerable($locked, $side));
    }

    /**
     * Ends a part with `$bp` (basis points) of it for the freelancer — of its amount, its client fee and its commission — and the rest back
     * to the client; only what is used is captured from the holds. Also how a dispute is split (FL-507). `$check` runs on the locked row.
     */
    public function settleShare(Milestone $m, int $bp, string $actorType, ?int $actorId, ?\Closure $check = null, bool $notify = true): Milestone
    {
        $agreement = $m->agreement;
        $project = $agreement->project;
        $side = $actorType;
        $bp = max(0, min(10000, $bp));
        $done = DB::transaction(function () use ($m, $agreement, $project, $side, $actorId, $bp, $check) {
            $locked = $this->lock($m);
            if ($check !== null) {
                $check($locked);
            }
            $share = intdiv($m->amount_minor * $bp, 10000);
            $feeUsed = intdiv($m->client_fee_minor * $bp, 10000);
            $commission = intdiv($m->commission_minor * $bp, 10000);
            $used = $share + $feeUsed;
            $net = $share - $commission;
            $held = $this->payments->held($m);
            // Never pay out what isn't held (a hold freed by mistake, a fault): nothing moves, the part stays as it is.
            if ($held !== $m->amount_minor + $m->client_fee_minor) {
                throw new FreelanceException('milestone_not_funded', 409);
            }
            $refund = $held - $used;

            $moved = $this->work->move($m, Status::Cancelled, 'milestone.cancelled', $side, $actorId, [
                'paid_minor' => $net, 'refunded_minor' => $refund, 'cancel_requested_by' => null, 'cancel_requested_at' => null,
            ], ['share_bp' => $bp, 'paid_minor' => $net, 'refunded_minor' => $refund]);

            // Only what was used is captured, spend-only money first; what is left in a hold is freed with it.
            $remaining = $used;
            $last = null;
            foreach ($this->payments->activeHolds($m) as $hold) {
                $part = min($remaining, (int) $hold->amount_minor);
                if ($part > 0) {
                    $last = $this->wallets->capture($hold, $part, WalletTransactionType::ServicePayment, [
                        'reference_type' => MilestonePayments::REFERENCE, 'reference_id' => $m->id,
                        'notes' => ['key' => 'wallet.notes.freelance_payment', 'variables' => ['project' => (string) $project->title]],
                        'idempotency_key' => "freelance:milestone:{$m->uuid}:cancel-pay:{$hold->id}",
                    ]);
                    $remaining -= $part;
                } else {
                    $this->wallets->release($hold, 'freelance_cancelled');
                }
            }

            $country = Country::query()->with('currency')->findOrFail($project->country_id);
            // FL-505: the freelancer's share goes to the wallet now, or waits its days.
            $credited = app(FreelanceEarnings::class)->credit($m, $agreement, $country, $net, $side === 'admin' ? 'dispute' : 'ended', "freelance:milestone:{$m->uuid}:cancel-earning");
            $earning = $credited?->wallet_transaction_id === null ? null : \Modules\Wallet\Models\WalletTransaction::query()->find($credited->wallet_transaction_id);
            $this->ledger->clientFee($feeUsed, $country->currency, $country, $m, (string) $project->title, $last);
            $this->ledger->commission($commission, $country->currency, $country, $m, (string) $project->title, $earning);
            // FL-511: a receipt for each side, with the movement.
            $reason = $side === 'admin' ? 'dispute' : 'ended';
            $receipts = app(FreelanceReceipts::class);
            $receipts->issue($moved, $agreement, 'client', 'settled', $reason, ['amount' => $share, 'fee' => $feeUsed, 'total' => $used, 'refunded' => $refund]);
            $receipts->issue($moved, $agreement, 'freelancer', 'earned', $reason, ['amount' => $share, 'fee' => $commission, 'total' => $net]);

            return $moved;
        });

        if ($notify) {
            $this->tell($done, 'freelancer', 'milestone.cancelled', (int) $done->paid_minor);
            $this->tell($done, 'client', 'milestone.cancelled', (int) $done->refunded_minor);
        }
        $this->release->closeIfSettled($agreement);

        return $done;
    }

    /** The freelancer's share of a started part, in basis points: the agreement's frozen policy, else the default. */
    private function shareBp(\Modules\Freelance\Models\FreelanceAgreement $a): int
    {
        $v = ((array) $a->policy)['cancellation.started_freelancer_bp'] ?? config('freelance.policy_defaults.cancellation.started_freelancer_bp');

        return max(0, min(10000, (int) ($v ?? 0)));
    }

    /** The part, locked, with its agreement (fresh: the request and the status as they are now). */
    private function lock(Milestone $m): Milestone
    {
        return Milestone::query()->whereKey($m->id)->lockForUpdate()->firstOrFail()->setRelation('agreement', $m->agreement);
    }

    private function assertParty(Milestone $m, Model $actor, string $side): void
    {
        $id = $side === 'client' ? $m->agreement->client_id : $m->agreement->freelancer_id;
        abort_unless((int) $id === (int) $actor->getKey(), 404);
    }

    /** There must be a request, from the other side, still in time. */
    private function assertAnswerable(Milestone $m, string $side): void
    {
        if (! $this->open($m) || $m->cancel_requested_by === $side || ! in_array($m->status, [Status::InProgress, Status::Revision], true)) {
            throw new FreelanceException('milestone_no_cancel_request', 409);
        }
    }

    /** @param  'client'|'freelancer'  $to */
    private function tell(Milestone $m, string $to, string $event, ?int $amount = null): void
    {
        $a = $m->agreement;
        $vars = ['project' => (string) $a->project->title] + ($amount === null ? [] : ['amount' => number_format($amount / 100, 2).' '.$a->currency_code]);
        $this->notifier->notify($to === 'client' ? $a->client : $a->freelancer, $event, $vars, ['project' => $a->project->uuid, 'milestone' => $m->uuid]);
    }
}
