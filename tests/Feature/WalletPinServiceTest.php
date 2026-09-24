<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\User\Models\User;
use Modules\Wallet\Exceptions\PinLockedException;
use Modules\Wallet\Exceptions\PinMismatchException;
use Modules\Wallet\Exceptions\PinNotSetException;
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

    public function test_five_wrong_attempts_lock_the_pin(): void
    {
        $this->pins->set($this->user, '1234');

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->pins->verify($this->user, '0000');
            } catch (PinMismatchException) {
                // expected for every attempt in this loop
            }
        }

        $this->expectException(PinLockedException::class);

        $this->pins->verify($this->user, '1234');
    }

    public function test_owner_type_is_stored_as_the_registered_morph_alias(): void
    {
        $this->pins->set($this->user, '1234');

        $this->assertDatabaseHas('wallet_pins', ['owner_type' => 'user']);
    }
}
