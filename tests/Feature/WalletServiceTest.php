<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletHoldStatus;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\IdempotencyConflictException;
use Modules\Wallet\Exceptions\InsufficientBalanceException;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    private Country $country;

    private User $me;

    private User $friend;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallets = app(WalletService::class);

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);
        $this->country = Country::create([
            'code' => 'SA',
            'dial_code' => '+966',
            'phone_length' => 9,
            'is_default' => true,
            'flag_id' => $flag->id,
            'currency_id' => $currency->id,
            'status' => true,
        ]);

        $this->me = User::create(['name' => 'Me', 'phone' => '966500000001', 'country_id' => $this->country->id, 'status' => 'active']);
        $this->friend = User::create(['name' => 'Friend', 'phone' => '966500000002', 'country_id' => $this->country->id, 'status' => 'active']);
    }

    private function walletFor(User $user): Wallet
    {
        return $this->wallets->firstOrCreateWallet($user, $this->country);
    }

    /**
     * The exact worked example from wallet-plan.md §10 / wallet-structure.md
     * §10 — this is the acceptance test for the whole withdrawable/spend_only
     * flag, not just a nice-to-have.
     */
    public function test_topup_transfer_and_return_scenario_matches_the_worked_example(): void
    {
        $me = $this->walletFor($this->me);
        $friend = $this->walletFor($this->friend);

        // 1) I top up 1000.
        $this->wallets->credit($me, 1000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $me->refresh();
        $this->assertSame(1000, $me->withdrawable_minor);
        $this->assertSame(0, $me->spend_only_minor);

        // 2) I transfer 500 (my withdrawable) to my friend.
        $this->wallets->transfer($me, $friend, 500, WalletBucket::Withdrawable);
        $me->refresh();
        $friend->refresh();
        $this->assertSame(500, $me->withdrawable_minor);
        $this->assertSame(0, $friend->withdrawable_minor);
        $this->assertSame(500, $friend->spend_only_minor); // received = always Hold

        // 3) My friend sends the same 500 back to me.
        $this->wallets->transfer($friend, $me, 500, WalletBucket::SpendOnly);
        $me->refresh();
        $friend->refresh();
        $this->assertSame(0, $friend->spend_only_minor);
        $this->assertSame(500, $me->withdrawable_minor); // unchanged
        $this->assertSame(500, $me->spend_only_minor); // the returned money is Hold too

        // Withdrawable available = 500, NOT 1000 — the returned transfer
        // never becomes withdrawable again (wallet-plan.md §10's central rule).
        $this->assertSame(500, $me->availableMinor(WalletBucket::Withdrawable));
        $this->assertSame(1000, $me->totalBalanceMinor());
    }

    public function test_debit_cannot_push_spend_only_below_zero(): void
    {
        $wallet = $this->walletFor($this->me);
        $this->wallets->credit($wallet, 100, WalletBucket::SpendOnly, WalletTransactionType::TransferIn);

        $this->expectException(InsufficientBalanceException::class);

        $this->wallets->debit($wallet, 200, WalletBucket::SpendOnly, WalletTransactionType::ServicePayment);
    }

    public function test_withdrawable_is_allowed_to_go_negative(): void
    {
        $wallet = $this->walletFor($this->me);

        $this->wallets->debit($wallet, 50, WalletBucket::Withdrawable, WalletTransactionType::Penalty);

        $wallet->refresh();
        $this->assertSame(-50, $wallet->withdrawable_minor);
    }

    public function test_wallet_transactions_cannot_be_updated_or_deleted(): void
    {
        $wallet = $this->walletFor($this->me);
        $transaction = $this->wallets->credit($wallet, 100, WalletBucket::Withdrawable, WalletTransactionType::Topup);

        $this->expectException(LogicException::class);
        $transaction->update(['amount_minor' => 1]);
    }

    public function test_wallet_transactions_cannot_be_deleted(): void
    {
        $wallet = $this->walletFor($this->me);
        $transaction = $this->wallets->credit($wallet, 100, WalletBucket::Withdrawable, WalletTransactionType::Topup);

        $this->expectException(LogicException::class);
        $transaction->delete();
    }

    public function test_same_idempotency_key_and_same_request_replays_without_double_posting(): void
    {
        $wallet = $this->walletFor($this->me);

        $first = $this->wallets->credit($wallet, 100, WalletBucket::Withdrawable, WalletTransactionType::Topup, [
            'idempotency_key' => 'topup-abc',
        ]);
        $second = $this->wallets->credit($wallet, 100, WalletBucket::Withdrawable, WalletTransactionType::Topup, [
            'idempotency_key' => 'topup-abc',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WalletTransaction::query()->where('idempotency_key', 'topup-abc')->count());

        $wallet->refresh();
        $this->assertSame(100, $wallet->withdrawable_minor); // posted once, not twice
    }

    public function test_same_idempotency_key_with_different_amount_is_rejected(): void
    {
        $wallet = $this->walletFor($this->me);

        $this->wallets->credit($wallet, 100, WalletBucket::Withdrawable, WalletTransactionType::Topup, [
            'idempotency_key' => 'topup-xyz',
        ]);

        $this->expectException(IdempotencyConflictException::class);

        $this->wallets->credit($wallet, 200, WalletBucket::Withdrawable, WalletTransactionType::Topup, [
            'idempotency_key' => 'topup-xyz',
        ]);
    }

    public function test_hold_reserves_the_amount_without_posting_a_transaction(): void
    {
        $wallet = $this->walletFor($this->me);
        $this->wallets->credit($wallet, 1000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $countBefore = WalletTransaction::query()->count();

        $hold = $this->wallets->hold($wallet, 300, WalletBucket::Withdrawable, ['reason_code' => 'service_estimate']);

        $this->assertSame($countBefore, WalletTransaction::query()->count()); // no ledger row for a hold
        $this->assertSame(WalletHoldStatus::Active, $hold->status);

        $wallet->refresh();
        $this->assertSame(1000, $wallet->withdrawable_minor); // balance itself untouched
        $this->assertSame(300, $wallet->held_withdrawable_minor);
        $this->assertSame(700, $wallet->availableMinor(WalletBucket::Withdrawable));
    }

    public function test_partial_capture_posts_only_the_captured_amount_and_frees_the_rest(): void
    {
        $wallet = $this->walletFor($this->me);
        $this->wallets->credit($wallet, 1000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $hold = $this->wallets->hold($wallet, 300, WalletBucket::Withdrawable);

        $transaction = $this->wallets->capture($hold, 250, WalletTransactionType::ServicePayment);

        $this->assertSame(250, $transaction->amount_minor);

        $wallet->refresh();
        $hold->refresh();
        $this->assertSame(750, $wallet->withdrawable_minor); // 1000 - 250 captured
        $this->assertSame(0, $wallet->held_withdrawable_minor); // whole hold released, not just 250
        $this->assertSame(750, $wallet->availableMinor(WalletBucket::Withdrawable)); // the 50 difference is free again
        $this->assertSame(WalletHoldStatus::Captured, $hold->status);
        $this->assertSame(250, $hold->captured_amount_minor);
    }

    public function test_release_frees_the_hold_with_no_new_transaction(): void
    {
        $wallet = $this->walletFor($this->me);
        $this->wallets->credit($wallet, 1000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $hold = $this->wallets->hold($wallet, 300, WalletBucket::Withdrawable);
        $countBefore = WalletTransaction::query()->count();

        $this->wallets->release($hold, 'booking_cancelled');

        $this->assertSame($countBefore, WalletTransaction::query()->count());

        $wallet->refresh();
        $hold->refresh();
        $this->assertSame(1000, $wallet->withdrawable_minor);
        $this->assertSame(0, $wallet->held_withdrawable_minor);
        $this->assertSame(WalletHoldStatus::Released, $hold->status);
    }

    public function test_expired_active_hold_is_released_by_the_reconcile_command(): void
    {
        $wallet = $this->walletFor($this->me);
        $this->wallets->credit($wallet, 1000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $hold = $this->wallets->hold($wallet, 300, WalletBucket::Withdrawable, ['expires_at' => now()->subMinute()]);

        Artisan::call('wallet:reconcile');

        $hold->refresh();
        $wallet->refresh();
        // releaseExpiredHolds() releases through the normal release() path
        // (reason 'expired') rather than a separate status — see
        // Modules\Wallet\Services\WalletService::releaseExpiredHolds().
        $this->assertSame(WalletHoldStatus::Released, $hold->status);
        $this->assertSame(0, $wallet->held_withdrawable_minor);
    }

    public function test_reconcile_command_finds_no_mismatch_after_a_sequence_of_operations(): void
    {
        $me = $this->walletFor($this->me);
        $friend = $this->walletFor($this->friend);

        $this->wallets->credit($me, 1000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $this->wallets->transfer($me, $friend, 300, WalletBucket::Withdrawable);
        $hold = $this->wallets->hold($me, 200, WalletBucket::Withdrawable);
        $this->wallets->capture($hold, 150, WalletTransactionType::ServicePayment);
        $this->wallets->debit($friend, 50, WalletBucket::SpendOnly, WalletTransactionType::ServicePayment);

        $exitCode = Artisan::call('wallet:reconcile');

        $this->assertSame(0, $exitCode);
        $me->refresh();
        $this->assertNotNull($me->last_reconciled_at);
    }

    public function test_reverse_posts_an_opposite_row_and_cannot_be_reversed_twice(): void
    {
        $wallet = $this->walletFor($this->me);
        $original = $this->wallets->credit($wallet, 500, WalletBucket::Withdrawable, WalletTransactionType::Topup);

        $reversal = $this->wallets->reverse($original, 'chargeback');

        $wallet->refresh();
        $this->assertSame(0, $wallet->withdrawable_minor);
        $this->assertSame($original->id, $reversal->reverses_transaction_id);
        $this->assertTrue($original->fresh()->isReversed());

        $this->expectException(LogicException::class);
        $this->wallets->reverse($original, 'chargeback again');
    }
}
