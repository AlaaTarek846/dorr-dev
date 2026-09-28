<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\User\Models\User;
use Modules\Wallet\Exceptions\PinFrozenException;
use Modules\Wallet\Exceptions\PinLockedException;
use Modules\Wallet\Exceptions\PinMismatchException;
use Modules\Wallet\Exceptions\PinNotSetException;
use Modules\Wallet\Models\WalletPin;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Support\OwnerType;
use Tests\TestCase;

class WalletPinServiceTest extends TestCase
{
    use RefreshDatabase;

    private PinService $pins;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pins = app(PinService::class);

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);
        $country = Country::create([
            'code' => 'SA',
            'dial_code' => '+966',
            'phone_length' => 9,
            'is_default' => true,
            'flag_id' => $flag->id,
            'currency_id' => $currency->id,
            'status' => true,
        ]);

        // Confirms WalletSettingObserver fired for real-time creation, not
        // just the WalletDatabaseSeeder backfill path.
        $this->assertDatabaseHas('wallet_settings', ['country_id' => $country->id]);

        $this->user = User::create([
            'name' => 'Test User',
            'phone' => '966500000000',
            'country_id' => $country->id,
            'status' => 'active',
        ]);
    }

    public function test_has_returns_false_before_any_pin_is_set(): void
    {
        $this->assertFalse($this->pins->has($this->user));
    }

    public function test_verify_throws_when_no_pin_exists_yet(): void
    {
        $this->expectException(PinNotSetException::class);

        $this->pins->verify($this->user, '1234');
    }

    public function test_correct_pin_verifies_and_resets_failed_attempts(): void
    {
        $this->pins->set($this->user, '1234');

        $this->pins->verify($this->user, '1234');

        $this->assertTrue($this->pins->has($this->user));
        $this->assertDatabaseHas('wallet_pins', [
            'owner_type' => OwnerType::aliasFor($this->user),
            'owner_id' => $this->user->id,
            'failed_attempts' => 0,
        ]);
    }

    public function test_wrong_pin_throws_mismatch_and_counts_the_failure(): void
    {
        $this->pins->set($this->user, '1234');

        $this->expectException(PinMismatchException::class);

        try {
            $this->pins->verify($this->user, '9999');
        } finally {
            $this->assertDatabaseHas('wallet_pins', [
                'owner_type' => OwnerType::aliasFor($this->user),
                'owner_id' => $this->user->id,
                'failed_attempts' => 1,
            ]);
        }
    }

    public function test_two_wrong_attempts_lock_the_pin_temporarily(): void
    {
        $this->pins->set($this->user, '1234');

        try {
            $this->pins->verify($this->user, '0000');
        } catch (PinMismatchException) {
            // the first wrong attempt just counts, nothing locks yet
        }

        $this->expectException(PinLockedException::class);

        // the second wrong attempt starts a 15-minute lock — even the right PIN is refused while it lasts
        $this->pins->verify($this->user, '0000');
    }

    public function test_a_wrong_attempt_right_after_the_temporary_lock_freezes_the_pin_for_good(): void
    {
        $this->pins->set($this->user, '1234');

        try {
            $this->pins->verify($this->user, '0000');
        } catch (PinMismatchException) {
        }
        try {
            $this->pins->verify($this->user, '0000'); // starts the temporary lock
        } catch (PinLockedException) {
        }

        // Nothing auto-unlocks; this only fast-forwards the wait so the next attempt is the "grace" one.
        WalletPin::query()->update(['locked_until' => now()->subMinute()]);
        $this->assertFalse($this->pins->isFrozen($this->user));

        // Wrong again, right after the lock: this is a *permanent* freeze, not another temporary lock.
        try {
            $this->pins->verify($this->user, '0000');
            $this->fail('expected a PinFrozenException');
        } catch (PinFrozenException) {
        }

        $this->assertTrue($this->pins->isFrozen($this->user));

        // The freeze beats everything, including the correct PIN.
        $this->expectException(PinFrozenException::class);
        $this->pins->verify($this->user, '1234');
    }

    public function test_setting_a_new_pin_lifts_a_freeze(): void
    {
        $this->pins->set($this->user, '1234');
        WalletPin::query()->update(['frozen_at' => now()]);
        $this->assertTrue($this->pins->isFrozen($this->user));

        $this->pins->set($this->user, '5678');

        $this->assertFalse($this->pins->isFrozen($this->user));
    }

    public function test_owner_type_is_stored_as_the_registered_morph_alias(): void
    {
        $this->pins->set($this->user, '1234');

        $this->assertDatabaseHas('wallet_pins', ['owner_type' => 'user']);
    }
}
