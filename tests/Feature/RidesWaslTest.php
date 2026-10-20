<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Discover\Models\DiscoverCity;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Driver\Models\Vehicle;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Rides\Models\DriverRegulatorStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideRegulatorCity;
use Modules\Rides\Models\WaslLog;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Regulator\Wasl\WaslDriverService;
use Modules\Rides\Support\Hijri;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Wasl as Jawad runs it in production (docs/rides-plan.md §11): register the driver and vehicle,
 * the daily eligibility check and the criminal record, completed trips with the official city name
 * and retries, and the working drivers' locations. Every driver goes through Wasl — no exception.
 */
class RidesWaslTest extends TestCase
{
    private Country $saudi;

    private DiscoverCity $riyadh;

    private DiscoverCity $jeddah;

    /** @var array<string, mixed> path => answer */
    private array $wasl = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'null',
            'rides.regulator_enforced' => true,
            'rides.wasl.url' => 'https://wasl.test/api',
            'rides.wasl.app_id' => 'APP', 'rides.wasl.app_key' => 'KEY', 'rides.wasl.client_id' => 'CLIENT',
        ]);
        Http::fake(function (Request $request) {
            foreach ($this->wasl as $path => $answer) {
                if (str_contains($request->url(), 'wasl.test/api'.$path) && ! str_contains($request->url(), $path.'/')) {
                    return is_callable($answer) ? $answer($request) : $answer;
                }
            }

            return Http::response([], 200);
        });

        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2])->id,
        ]);
        $this->riyadh = DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 24.7136, 'lng' => 46.6753, 'status' => true, 'sort_order' => 1]);
        $this->jeddah = DiscoverCity::query()->create(['country_id' => $this->saudi->id, 'timezone' => 'Asia/Riyadh', 'lat' => 21.4858, 'lng' => 39.1925, 'status' => true, 'sort_order' => 2]);
        RideRegulatorCity::query()->create(['regulator' => 'wasl', 'country_id' => $this->saudi->id, 'city_id' => $this->riyadh->id, 'official_name' => 'الرياض', 'status' => true]);
        CityResolver::forget();
    }

    public function test_registering_sends_jawads_body_and_an_approved_driver_may_work(): void
    {
        $driver = $this->driver('1012345678');
        $this->assertSame('regulator_pending', app(DriverEligibility::class)->reasonNotToWork($driver), 'nobody works before Wasl answers');

        $this->wasl['/dispatching/v2/drivers'] = Http::response(['success' => true, 'resultCode' => 'success', 'result' => [
            'eligibility' => 'VALID', 'eligibilityExpiryDate' => '2027-05-01', 'vehicleEligibility' => 'VALID',
            'vehicleLicenseExpiryDate' => '22/04/1449', 'driverFullNameArabic' => 'محمد أحمد', 'driverFullNameEnglish' => 'Mohammed Ahmed',
            'driverGender' => 'MALE', 'rejectionReasons' => [],
        ]]);

        $this->actingAs($driver, 'driver_api')->postJson('/api/driver/v1/regulator/register')->assertOk()
            ->assertJsonPath('data.driver_status', 'valid')
            ->assertJsonPath('data.vehicle_status', 'valid')
            ->assertJsonPath('data.vehicle_expires_at', Hijri::toGregorian('22/04/1449'))
            ->assertJsonPath('data.work_problem', null);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/dispatching/v2/drivers')
                && $request->header('app_id') === ['APP'] && $request->header('client-id') === ['CLIENT']
                && $body['driver']['identityNumber'] === '1012345678'
                && $body['driver']['dateOfBirthHijri'] === '1410/10/20'
                && $body['driver']['dateOfBirthGregorian'] === '1990-05-15'
                && $body['driver']['mobileNumber'] === '+966551234567'
                && [$body['vehicle']['plateLetterLeft'], $body['vehicle']['plateLetterMiddle'], $body['vehicle']['plateLetterRight']] === ['ا', 'ب', 'ج']
                && $body['vehicle']['sequenceNumber'] === '987654321';
        });
        $this->assertSame('محمد أحمد', $driver->fresh()->name_ar);
        $status = DriverRegulatorStatus::query()->sole();
        $this->assertSame('22/04/1449', $status->vehicle_expires_at_hijri, 'the Hijri date is kept as Wasl sent it');
        $this->assertNotNull($status->last_registered_at);
        $this->assertSame(1, $status->registration_attempts);
        $log = WaslLog::query()->sole();
        $this->assertTrue($log->success);
        $this->assertSame('registration', $log->type);
        $this->assertSame(200, $log->status_code);
        $this->assertSame('1012345678', $log->request['driver']['identityNumber']);
        $this->assertSame('VALID', $log->response['result']['eligibility']);
        $this->assertTrue($driver->is($log->subject()));
        $this->assertStringNotContainsString('KEY', json_encode($log->request), 'the keys are never logged');
    }

    public function test_every_driver_is_sent_and_a_refusal_comes_back_in_the_drivers_language(): void
    {
        // A resident's iqama (starts with 2) is sent like anyone else's; Wasl decides.
        $driver = $this->driver('2012345678');
        $this->wasl['/dispatching/v2/drivers'] = Http::response(['success' => true, 'result' => [
            'eligibility' => 'INVALID', 'vehicleEligibility' => 'VALID', 'rejectionReasons' => ['DRIVER_NOT_ALLOWED'],
        ]]);

        $this->actingAs($driver, 'driver_api')->postJson('/api/driver/v1/regulator/register')->assertOk()
            ->assertJsonPath('data.driver_status', 'invalid')
            ->assertJsonPath('data.reasons.0.code', 'DRIVER_NOT_ALLOWED')
            ->assertJsonPath('data.reasons.0.message', __('wasl.codes.DRIVER_NOT_ALLOWED'))
            ->assertJsonPath('data.work_problem', 'regulator_invalid');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/dispatching/v2/drivers') && $r->data()['driver']['identityNumber'] === '2012345678');
    }

    public function test_missing_details_are_named_before_anything_is_sent(): void
    {
        $driver = $this->driver('1012345678');
        $driver->update(['date_of_birth' => null]);

        $this->actingAs($driver, 'driver_api')->postJson('/api/driver/v1/regulator/register')
            ->assertStatus(422)->assertJsonPath('error_code', 'rides_regulator_missing_data')->assertJsonPath('data.fields', ['date_of_birth']);
        Http::assertNothingSent();
    }

    public function test_a_failed_criminal_record_blocks_registration_for_eleven_months(): void
    {
        $driver = $this->driver('1012345678');
        $this->wasl['/dispatching/v2/drivers/eligibility'] = Http::response([[
            'driverEligibility' => 'REJECTED', 'criminalRecordStatus' => 'DONE_RESULT_NOT_OK',
            'driverRejectionReason' => ['DRIVER_FAILED_CRIMINAL_RECORD_CHECK'], 'vehicles' => [['vehicleEligibility' => 'VALID']],
        ]]);

        $this->artisan('rides:wasl-eligibility')->assertSuccessful();

        $status = DriverRegulatorStatus::query()->sole();
        $this->assertSame('rejected', $status->driver_status);
        $this->assertSame(now()->addMonths(11)->toDateString(), $status->blocked_until->toDateString());
        $this->actingAs($driver, 'driver_api')->postJson('/api/driver/v1/regulator/register')
            ->assertStatus(409)->assertJsonPath('error_code', 'rides_regulator_blocked');
    }

    public function test_driver_and_vehicle_reasons_stay_apart_and_wasls_words_are_kept(): void
    {
        $driver = $this->approved($this->driver('1012345678'));
        $this->wasl['/dispatching/v2/drivers/eligibility'] = Http::response([[
            'driverEligibility' => 'VALID', 'criminalRecordStatus' => 'DONE_RESULT_OK',
            'vehicles' => [['vehicleEligibility' => 'EXPIRED', 'vehicleLicenseExpiryDate' => '01/01/1447', 'vehicleRejectionReason' => ['VEHICLE_INSURANCE_EXPIRED']]],
        ]]);

        $this->actingAs($driver, 'driver_api')->postJson('/api/driver/v1/regulator/check')->assertOk()
            ->assertJsonPath('data.driver_status', 'valid')
            ->assertJsonPath('data.vehicle_status', 'expired')
            ->assertJsonPath('data.vehicle_expires_at_hijri', '01/01/1447')
            ->assertJsonPath('data.reasons.0.about', 'vehicle')
            ->assertJsonPath('data.reasons.0.code', 'VEHICLE_INSURANCE_EXPIRED')
            ->assertJsonPath('data.work_problem', 'regulator_expired');
        $status = DriverRegulatorStatus::query()->sole();
        $this->assertSame([], $status->rejection_codes);
        $this->assertSame(['VEHICLE_INSURANCE_EXPIRED'], $status->vehicle_rejection_codes);
    }

    public function test_the_daily_check_keeps_an_approval_current_and_spots_an_expiry(): void
    {
        $driver = $this->approved($this->driver('1012345678'));
        $this->wasl['/dispatching/v2/drivers/eligibility'] = Http::response([[
            'driverEligibility' => 'VALID', 'eligibilityExpiryDate' => now()->subDay()->toDateString(), 'criminalRecordStatus' => 'DONE_RESULT_OK',
            'vehicles' => [['vehicleEligibility' => 'VALID']],
        ]]);

        app(WaslDriverService::class)->check($driver);

        $this->assertSame('regulator_expired', app(DriverEligibility::class)->reasonNotToWork($driver));
    }

    public function test_a_completed_trip_is_registered_with_the_official_city_name(): void
    {
        $driver = $this->approved($this->driver('1012345678'));
        $ride = $this->completedRide($driver);
        $this->wasl['/dispatching/v2/trips'] = Http::response(['success' => true, 'resultCode' => 'success']);

        $this->artisan('rides:wasl-trips')->assertSuccessful();

        $ride->refresh();
        $this->assertSame('sent', $ride->wasl_status->value);
        Http::assertSent(function (Request $request) use ($ride) {
            $body = $request->data();

            return str_ends_with($request->url(), '/dispatching/v2/trips')
                && $body['tripId'] === $ride->number
                && $body['driverId'] === '1012345678'
                && $body['originCityNameInArabic'] === 'الرياض' && $body['destinationCityNameInArabic'] === 'الرياض'
                && $body['distanceInMeters'] === 5200 && $body['tripCost'] === 25.5
                && $body['dropoffTimestamp'] === $ride->completed_at->copy()->setTimezone('Asia/Riyadh')->format('Y-m-d\TH:i:s.v');
        });
        $this->assertNotNull(RideRegulatorCity::query()->sole()->accepted_at, 'an accepted name is locked');
        $this->assertSame(['الرياض', 'الرياض'], [$ride->wasl_origin_city, $ride->wasl_destination_city]);
    }

    public function test_a_failed_trip_is_retried_on_jawads_schedule_and_a_final_code_stops_it(): void
    {
        $driver = $this->approved($this->driver('1012345678'));
        $ride = $this->completedRide($driver);
        $this->wasl['/dispatching/v2/trips'] = Http::response(['success' => false, 'resultCode' => 'TEMP_ERR', 'resultMsg' => 'Temporary error'], 200);

        $this->artisan('rides:wasl-trips');
        $this->assertSame(1, $ride->refresh()->wasl_attempts);
        $this->assertSame('pending', $ride->wasl_status->value);
        $this->assertSame('Temporary error', $ride->wasl_result_message);

        $this->artisan('rides:wasl-trips');
        $this->assertSame(1, $ride->refresh()->wasl_attempts, 'the next try waits a minute');

        $this->travel(61)->seconds();
        $this->wasl['/dispatching/v2/trips'] = Http::response(['success' => false, 'resultCode' => 'DUPLICATE_TRIP'], 200);
        $this->artisan('rides:wasl-trips');
        $this->assertSame('failed', $ride->refresh()->wasl_status->value);
        $this->assertSame('DUPLICATE_TRIP', $ride->wasl_result_code);
    }

    public function test_a_place_without_an_official_name_is_never_guessed(): void
    {
        $driver = $this->approved($this->driver('1012345678'));
        $ride = $this->completedRide($driver, toJeddah: true);

        $this->artisan('rides:wasl-trips');

        $this->assertSame('CITY_UNMAPPED', $ride->refresh()->wasl_result_code);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/dispatching/v2/trips'));
    }

    public function test_working_drivers_locations_go_to_wasl_with_has_customer(): void
    {
        $driver = $this->approved($this->driver('1012345678'));
        DriverPresence::query()->create(['driver_id' => $driver->id, 'status' => PresenceStatus::Online, 'lat' => 24.71, 'lng' => 46.67, 'gps_enabled' => true, 'last_seen_at' => now()]);
        $quiet = $this->approved($this->driver('1099999999', '+966559999999', 2));
        DriverPresence::query()->create(['driver_id' => $quiet->id, 'status' => PresenceStatus::Online, 'lat' => 24.72, 'lng' => 46.68, 'gps_enabled' => true, 'last_seen_at' => now()->subMinutes(10)]);

        $this->artisan('rides:wasl-locations')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            $locations = $request->data()['locations'] ?? [];

            return str_ends_with($request->url(), '/dispatching/v2/locations') && count($locations) === 1
                && $locations[0]['driverIdentityNumber'] === '1012345678' && $locations[0]['hasCustomer'] === false
                && $locations[0]['vehicleSequenceNumber'] === '987654321';
        });
    }

    // ---------------------------------------------------------------- helpers

    private function driver(string $nationalId, string $phone = '+966551234567', int $n = 1): Driver
    {
        $driver = Driver::query()->create([
            'uuid' => (string) Str::uuid(), 'name' => 'Captain', 'phone' => $phone, 'phone_verified_at' => now(),
            'country_id' => $this->saudi->id, 'status' => DriverStatus::Active, 'national_id' => $nationalId, 'id_type' => 'national_id',
            'date_of_birth' => '1990-05-15', 'locale' => 'ar',
        ]);
        $make = VehicleMake::query()->create(['status' => true]);
        Vehicle::query()->create([
            'uuid' => (string) Str::uuid(), 'driver_id' => $driver->id, 'country_id' => $this->saudi->id, 'make_id' => $make->id,
            'model_id' => VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'sedan', 'seats' => 4, 'status' => true])->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2023, 'body_type' => 'sedan', 'seats' => 4, 'plate_letters' => 'ا ب ج', 'plate_number' => (string) (1233 + $n), 'plate_type' => 1,
            'sequence_number' => $n === 1 ? '987654321' : '98765432'.$n, 'status' => VehicleStatus::Approved, 'is_active' => true,
        ]);

        return $driver;
    }

    private function approved(Driver $driver): Driver
    {
        DriverRegulatorStatus::query()->create([
            'driver_id' => $driver->id, 'vehicle_id' => $driver->vehicles()->value('id'), 'regulator' => 'wasl', 'country_id' => $this->saudi->id,
            'driver_status' => 'valid', 'vehicle_status' => 'valid', 'driver_expires_at' => now()->addYear(), 'last_checked_at' => now(),
        ]);

        return $driver;
    }

    private function completedRide(Driver $driver, bool $toJeddah = false): Ride
    {
        $rider = User::query()->create(['name' => 'Sara', 'phone' => '+96650000000'.random_int(1, 9), 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $category = RideCategory::query()->firstOrCreate(['slug' => 'economy'], ['pricing_strategy' => 'distance', 'status' => true]);

        return Ride::query()->create([
            'uuid' => (string) Str::uuid(), 'number' => 'DR-261014-0001', 'user_id' => $rider->id, 'driver_id' => $driver->id,
            'vehicle_id' => $driver->vehicles()->value('id'), 'category_id' => $category->id, 'country_id' => $this->saudi->id,
            'city_id' => $this->riyadh->id, 'currency_id' => $this->saudi->currency_id, 'status' => 'completed', 'booking_mode' => 'instant',
            'pricing_mode' => 'upfront', 'payment_method' => 'cash', 'payment_status' => 'cash_collected', 'confirmed_snapshot' => [],
            'origin_lat' => 24.7136, 'origin_lng' => 46.6753, 'origin_address' => 'Olaya',
            'destination_lat' => $toJeddah ? 21.4858 : 24.7500, 'destination_lng' => $toJeddah ? 39.1925 : 46.7000,
            'requested_at' => now()->subMinutes(30), 'accepted_at' => now()->subMinutes(28), 'arrived_at' => now()->subMinutes(22),
            'started_at' => now()->subMinutes(20), 'completed_at' => now()->subMinute(), 'pickup_waiting_s' => 120,
            'metered_distance_m' => 5200, 'metered_duration_s' => 1140, 'final_total_minor' => 2550, 'wasl_status' => 'pending',
        ]);
    }
}
