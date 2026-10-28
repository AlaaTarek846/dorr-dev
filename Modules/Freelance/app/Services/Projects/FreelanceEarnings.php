<?php

namespace Modules\Freelance\Services\Projects;

use App\Models\Country;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as Status;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceEarning as Earning;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\WalletService;

/**
 * What a freelancer earns from the parts of their work (FL-505 / FL-509). Every net amount paid to a freelancer — a part approved, a part
 * ended with a share, a dispute decided — goes through `credit`: with no waiting days (the policy frozen in the agreement,
 * `earnings.hold_days`, default 0) it is in their wallet at once, as before; with some, it waits as `pending` and
 * `freelance:release-earnings` (hourly) puts it in the wallet when its day comes. Either way there is one row per movement (its key
 * makes a repeat harmless). DORR's own income (the client fee, the commission) is booked when the part is approved, not delayed.
 */
class FreelanceEarnings
{
    public function __construct(private readonly WalletService $wallets, private readonly FreelanceNotifier $notifier) {}

    /** Inside the paying transaction. */
    public function credit(Milestone $m, Agreement $a, Country $country, int $amount, string $kind, string $key): ?Earning
    {
        if ($amount <= 0) {
            return null;
        }
        $existing = Earning::query()->where('key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $days = max(0, (int) (((array) $a->policy)['earnings.hold_days'] ?? config('freelance.policy_defaults.earnings.hold_days') ?? 0));
        $earning = Earning::query()->create([
            'freelancer_id' => $a->freelancer_id, 'project_id' => $a->project_id, 'milestone_id' => $m->id, 'country_id' => $country->id, 'kind' => $kind,
            'amount_minor' => $amount, 'currency_code' => $a->currency_code, 'status' => $days === 0 ? Earning::RELEASED : Earning::PENDING,
            'release_at' => $days === 0 ? null : now()->addDays($days), 'key' => $key,
        ]);
        if ($days === 0) {
            $tx = $this->toWallet($earning, $a->freelancer, $country, (string) $a->project->title);
            $earning->forceFill(['released_at' => now(), 'wallet_transaction_id' => $tx->id])->save();
        }

        return $earning;
    }

    /** Hourly (FL-505): pending earnings whose day came go into the wallets, once each. */
    public function releaseDue(): int
    {
        $count = 0;
        Earning::query()->where('status', Earning::PENDING)->where('release_at', '<=', now())->pluck('id')->each(function (int $id) use (&$count): void {
            $released = DB::transaction(function () use ($id) {
                $e = Earning::query()->whereKey($id)->lockForUpdate()->with(['freelancer', 'project'])->first();
                if ($e === null || $e->status !== Earning::PENDING) {
                    return null;
                }
                $tx = $this->toWallet($e, $e->freelancer, Country::query()->findOrFail($e->country_id), (string) $e->project?->title);
                $e->forceFill(['status' => Earning::RELEASED, 'released_at' => now(), 'wallet_transaction_id' => $tx->id])->save();

                return $e;
            });
            if ($released !== null) {
                $this->notifier->notify($released->freelancer, 'payment.available', [
                    'project' => (string) $released->project?->title, 'amount' => number_format($released->amount_minor / 100, 2).' '.$released->currency_code,
                ], ['project' => $released->project?->uuid]);
                $count++;
            }
        });

        return $count;
    }

    /** @return array<string, mixed> what the freelancer's earnings screen shows */
    public function summary(Freelancer $f, int $recent = 30): array
    {
        $wallet = Wallet::query()->where('owner_type', 'freelancer')->where('owner_id', $f->id)->where('country_id', $f->country_id)->first();
        $pending = Earning::query()->where('freelancer_id', $f->id)->where('status', Earning::PENDING);
        // Held by clients for parts not approved yet, after the commission: what is on its way.
        $expected = (int) Milestone::query()->whereIn('status', [Status::Funded->value, Status::InProgress->value, Status::Delivered->value, Status::Revision->value, Status::Disputed->value])
            ->whereHas('agreement', fn ($q) => $q->where('freelancer_id', $f->id)->where('status', Agreement::ACTIVE))->sum('net_minor');

        return [
            'currency' => $wallet?->currency?->code ?? Earning::query()->where('freelancer_id', $f->id)->value('currency_code'),
            'available_minor' => $wallet === null ? 0 : max(0, $wallet->availableMinor(WalletBucket::Withdrawable)),
            'pending_minor' => (int) (clone $pending)->sum('amount_minor'),
            'next_release_at' => (clone $pending)->min('release_at') === null ? null : \Illuminate\Support\Carbon::parse((clone $pending)->min('release_at'))->toIso8601String(),
            'expected_minor' => $expected,
            'earned_minor' => (int) Earning::query()->where('freelancer_id', $f->id)->where('status', Earning::RELEASED)->sum('amount_minor'),
            'recent' => Earning::query()->where('freelancer_id', $f->id)->with(['project', 'milestone'])->latest('id')->limit($recent)->get()->map(fn (Earning $e) => [
                'id' => $e->id, 'kind' => $e->kind, 'amount_minor' => $e->amount_minor, 'currency' => $e->currency_code, 'status' => $e->status,
                'project' => ['uuid' => $e->project?->uuid, 'title' => $e->project?->title], 'milestone' => $e->milestone?->title,
                'release_at' => $e->release_at?->toIso8601String(), 'released_at' => $e->released_at?->toIso8601String(), 'at' => $e->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function toWallet(Earning $e, Freelancer $f, Country $country, string $project): WalletTransaction
    {
        return $this->wallets->credit($this->wallets->firstOrCreateWallet($f, $country), $e->amount_minor, WalletBucket::Withdrawable, WalletTransactionType::ServiceEarning, [
            'reference_type' => MilestonePayments::REFERENCE, 'reference_id' => $e->milestone_id,
            'notes' => ['key' => 'wallet.notes.freelance_earning', 'variables' => ['project' => $project]],
            // The same key the earnings had before FL-505, so an earning is never paid twice.
            'idempotency_key' => $e->key,
        ]);
    }
}
