<?php

namespace Modules\Freelance\Services\Projects;

use App\Models\Country;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletHold;
use Modules\Wallet\Services\WalletService;

/**
 * Funding a part of the work from the client's wallet (FL-501 / FL-502). The part's amount plus its client fee — the numbers kept
 * with the part when the agreement was made — is held in the client's wallet of the project's country, spend-only money first, then
 * withdrawable, like a ride. Nothing moves to the freelancer and nothing is booked yet: paying out on approval is FL-503, giving it
 * back FL-506. The hold has no end date (`expires_at = null`), so the wallet's safety valve that frees forgotten holds after a day
 * never touches it. Funding twice is harmless: the part is locked, an already funded part answers as it is, and each wallet hold has
 * a key of its own (`freelance:milestone:{uuid}:{bucket}`). Only a wallet: no gateway — not enough money answers what is missing
 * and the app opens the wallet top-up.
 */
class MilestonePayments
{
    public const REFERENCE = 'freelance_milestone';

    public function __construct(
        private readonly WalletService $wallets,
        private readonly MilestoneWork $work,
        private readonly FreelanceNotifier $notifier,
    ) {}

    /** @throws FreelanceException wallet_insufficient (422, with the shortfall), milestone_out_of_turn, milestone_invalid_state */
    public function fund(Milestone $milestone, User $client): Milestone
    {
        $agreement = $milestone->agreement;
        abort_unless((int) $agreement->client_id === (int) $client->id, 404);

        $funded = DB::transaction(function () use ($milestone, $client, $agreement) {
            /** @var Milestone $m */
            $m = Milestone::query()->whereKey($milestone->id)->lockForUpdate()->firstOrFail();
            // Sent twice (a weak network): the first one did it.
            if ($m->status !== Status::Unfunded) {
                if ($this->held($m) > 0) {
                    return null;
                }
                throw new FreelanceException('milestone_invalid_state', 409, ['status' => __('freelance.milestone_statuses.'.$m->status->value)], ['status' => $m->status->value]);
            }

            $needed = $m->amount_minor + $m->client_fee_minor;
            $wallet = $this->walletOf($client, (int) $agreement->project->country_id);
            if (strtoupper((string) $wallet->currency?->code) !== strtoupper((string) $agreement->currency_code)) {
                throw new FreelanceException('wallet_currency', 422, ['currency' => $agreement->currency_code], ['currency' => $agreement->currency_code]);
            }
            $available = $this->available($wallet);
            if ($available < $needed) {
                throw new FreelanceException('wallet_insufficient', 422, [
                    'needed' => self::money($needed, $agreement->currency_code), 'available' => self::money($available, $agreement->currency_code),
                ], ['needed_minor' => $needed, 'available_minor' => $available, 'shortfall_minor' => $needed - $available, 'currency' => $agreement->currency_code]);
            }

            $fromSpendOnly = min(max(0, $wallet->availableMinor(WalletBucket::SpendOnly)), $needed);
            foreach ([[WalletBucket::SpendOnly, $fromSpendOnly], [WalletBucket::Withdrawable, $needed - $fromSpendOnly]] as [$bucket, $part]) {
                if ($part <= 0) {
                    continue;
                }
                $this->wallets->hold($wallet, $part, $bucket, [
                    'reference_type' => self::REFERENCE,
                    'reference_id' => $m->id,
                    'reason_code' => 'freelance_milestone',
                    // FL-502: held until the part is approved or given back — never freed by the clock.
                    'expires_at' => null,
                    'idempotency_key' => "freelance:milestone:{$m->uuid}:{$bucket->value}",
                ]);
                $wallet->refresh();
            }

            $moved = $this->work->fund($m->setRelation('agreement', $agreement), 'client', $client->id);
            // FL-511: the client's receipt of the money held, with the movement.
            app(FreelanceReceipts::class)->issue($moved, $agreement, 'client', 'funded', null, ['amount' => $m->amount_minor, 'fee' => $m->client_fee_minor, 'total' => $needed]);

            return $moved;
        });

        if ($funded === null) {
            return $milestone->refresh();
        }
        $this->notifier->notify($agreement->freelancer, 'payment.held', [
            'project' => (string) $agreement->project->title, 'amount' => self::money($funded->amount_minor, $agreement->currency_code),
        ], ['project' => $agreement->project->uuid, 'milestone' => $funded->uuid]);

        return $funded;
    }

    /** What is held for the part right now. */
    public function held(Milestone $m): int
    {
        return (int) $this->activeHolds($m)->sum('amount_minor');
    }

    /** @return Collection<int, WalletHold> spend-only first */
    public function activeHolds(Milestone $m): Collection
    {
        return WalletHold::query()->where('reference_type', self::REFERENCE)->where('reference_id', $m->id)->where('status', WalletHoldStatus::Active)->get()
            ->sortBy(fn (WalletHold $h) => $h->bucket === WalletBucket::SpendOnly ? 0 : 1)->values();
    }

    /** What the client can spend now in the project's country (no wallet yet: nothing). */
    public function availableFor(User $client, int $countryId): int
    {
        $wallet = Wallet::query()->where('owner_type', 'user')->where('owner_id', $client->id)->where('country_id', $countryId)->first();

        return $wallet === null ? 0 : $this->available($wallet);
    }

    private function walletOf(User $client, int $countryId): Wallet
    {
        return $this->wallets->firstOrCreateWallet($client, Country::query()->findOrFail($countryId))->fresh(['currency']);
    }

    private function available(Wallet $wallet): int
    {
        return max(0, $wallet->availableMinor(WalletBucket::SpendOnly)) + max(0, $wallet->availableMinor(WalletBucket::Withdrawable));
    }

    private static function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2).' '.$currency;
    }
}
