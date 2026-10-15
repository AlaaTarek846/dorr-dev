<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Discover\Models\DiscoverCity;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Models\Driver;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\User\Models\User;
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * D-212: one statement row in full on the captain's wallet (the same route serves the rider's) — the
 * row, the ride it came from (to the people on that ride only), the other rows of the same operation,
 * and whether it was reversed. Another owner's row, or one from another country, is simply not found.
 */
class WalletTransactionDetailTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Driver $driver;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2])->id,
        ]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $this->saudi->flag_id]);
        DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        $this->seed(FinancialCategorySeeder::class);

        $this->wallets = app(WalletService::class);
        $this->driver = $this->person(Driver::class, 'Omar', '+966550000001');
    }

    public function test_a_ride_row_shows_its_ride_and_the_other_rows_of_the_same_operation(): void
    {
        $rider = $this->person(User::class, 'Sara Ahmed', '+966500000001');
        $ride = $this->ride($rider, $this->driver, 'DR-1001');
        $wallet = $this->wallets->firstOrCreateWallet($this->driver, $this->saudi);

        $operation = (string) Str::uuid();
        $earning = $this->wallets->credit($wallet, 8000, WalletBucket::Withdrawable, WalletTransactionType::Topup, [
            'operation_id' => $operation, 'reference_type' => 'ride', 'reference_id' => $ride->id,
            'notes' => ['key' => 'wallet.notes.topup', 'variables' => []],
        ]);
        $this->wallets->debit($wallet, 1600, WalletBucket::Withdrawable, WalletTransactionType::Penalty, [
            'operation_id' => $operation, 'reference_type' => 'ride', 'reference_id' => $ride->id,
        ]);
        $this->wallets->credit($wallet, 5, WalletBucket::Withdrawable, WalletTransactionType::Topup); // an unrelated row

        $res = $this->asDriver($this->driver)->getJson('/api/driver/v1/wallet/transactions/'.$earning->uuid, ['X-Country' => 'SA'])->assertOk();

        $res->assertJsonPath('data.uuid', $earning->uuid)->assertJsonPath('data.amount_minor', 8000)->assertJsonPath('data.direction', 'credit')
            ->assertJsonPath('data.reference', ['type' => 'ride', 'uuid' => $ride->uuid, 'number' => 'DR-1001', 'status' => 'completed'])
            ->assertJsonPath('data.reversed', false)->assertJsonPath('data.reverses_uuid', null);
        $related = $res->json('data.related');
        $this->assertCount(1, $related);
        $this->assertSame(['penalty', 'debit', 1600], [$related[0]['type'], $related[0]['direction'], $related[0]['amount_minor']]);
    }

    public function test_a_reversed_row_and_the_reversal_point_at_each_other(): void
    {
        $wallet = $this->wallets->firstOrCreateWallet($this->driver, $this->saudi);
        $original = $this->wallets->credit($wallet, 300, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $reversal = $this->wallets->reverse($original, 'mistake');

        $this->asDriver($this->driver)->getJson('/api/driver/v1/wallet/transactions/'.$original->uuid, ['X-Country' => 'SA'])->assertOk()
            ->assertJsonPath('data.reversed', true)->assertJsonPath('data.reverses_uuid', null)->assertJsonPath('data.reference', null);
        $this->asDriver($this->driver)->getJson('/api/driver/v1/wallet/transactions/'.$reversal->uuid, ['X-Country' => 'SA'])->assertOk()
            ->assertJsonPath('data.type', 'reversal')->assertJsonPath('data.reversed', false)->assertJsonPath('data.reverses_uuid', $original->uuid);
    }

    public function test_the_ride_is_only_shown_to_the_people_on_it(): void
    {
        $rider = $this->person(User::class, 'Sara Ahmed', '+966500000001');
        $otherCaptain = $this->person(Driver::class, 'Khaled', '+966550000002');
        $ride = $this->ride($rider, $otherCaptain, 'DR-2002');

        // A row that names a ride this captain wasn't on (a wrong or stale reference): the row, no ride.
        $wallet = $this->wallets->firstOrCreateWallet($this->driver, $this->saudi);
        $row = $this->wallets->credit($wallet, 100, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['reference_type' => 'ride', 'reference_id' => $ride->id]);

        $this->asDriver($this->driver)->getJson('/api/driver/v1/wallet/transactions/'.$row->uuid, ['X-Country' => 'SA'])->assertOk()->assertJsonPath('data.reference', null);

        // A reference type nobody resolves, and a missing ride: also just the row.
        $odd = $this->wallets->credit($wallet, 100, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['reference_type' => 'something_else', 'reference_id' => 5]);
        $gone = $this->wallets->credit($wallet, 100, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['reference_type' => 'ride', 'reference_id' => 999999]);
        $this->asDriver($this->driver)->getJson('/api/driver/v1/wallet/transactions/'.$odd->uuid, ['X-Country' => 'SA'])->assertOk()->assertJsonPath('data.reference', null);
        $this->asDriver($this->driver)->getJson('/api/driver/v1/wallet/transactions/'.$gone->uuid, ['X-Country' => 'SA'])->assertOk()->assertJsonPath('data.reference', null);
    }

    public function test_someone_elses_row_a_made_up_uuid_and_no_login_are_not_served(): void
    {
        $this->getJson('/api/driver/v1/wallet/transactions/'.Str::uuid(), ['X-Country' => 'SA'])->assertUnauthorized();

        $other = $this->person(Driver::class, 'Khaled', '+966550000002');
        $theirs = $this->wallets->credit($this->wallets->firstOrCreateWallet($other, $this->saudi), 900, WalletBucket::Withdrawable, WalletTransactionType::Topup);

        $this->asDriver($this->driver)->getJson('/api/driver/v1/wallet/transactions/'.$theirs->uuid, ['X-Country' => 'SA'])->assertNotFound();
        // No wallet at all yet → also not found, not an error.
        $this->asDriver($this->driver)->getJson('/api/driver/v1/wallet/transactions/'.Str::uuid(), ['X-Country' => 'SA'])->assertNotFound();
        $this->assertSame(1, WalletTransaction::query()->count());
        $this->assertSame(0, Wallet::query()->where('owner_id', $this->driver->id)->where('owner_type', 'driver')->count());
    }

    private function person(string $class, string $name, string $phone): Driver|User
    {
        return $class === Driver::class
            ? Driver::query()->create(['uuid' => (string) Str::uuid(), 'name' => $name, 'phone' => $phone, 'phone_verified_at' => now(), 'country_id' => $this->saudi->id, 'status' => DriverStatus::Active])
            : User::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function ride(User $rider, Driver $driver, string $number): Ride
    {
        $category = RideCategory::query()->firstOrCreate(['slug' => 'economy'], ['pricing_strategy' => 'distance', 'status' => true, 'sort_order' => 1, 'capacity_max' => 4]);

        return Ride::query()->create([
            'uuid' => (string) Str::uuid(), 'number' => $number, 'user_id' => $rider->id, 'driver_id' => $driver->id, 'category_id' => $category->id,
            'country_id' => $this->saudi->id, 'currency_id' => $this->saudi->currency_id, 'status' => 'completed', 'pricing_mode' => 'upfront',
            'confirmed_snapshot' => [], 'payment_method' => 'cash', 'payment_status' => 'cash_collected',
            'origin_lat' => 24.7136, 'origin_lng' => 46.6753, 'destination_lat' => 24.75, 'destination_lng' => 46.7,
        ]);
    }

    private function asDriver(Driver $driver): static
    {
        return $this->actingAs($driver, 'driver_api');
    }
}
