<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverDocumentRequirement;
use Modules\Driver\Models\DriverDocumentType;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Tests\TestCase;

/**
 * Signing up in the driver app (docs/rides-plan.md §11.3): details → vehicle → the documents
 * required from this driver → review; and the phone registering for ride-request pushes.
 */
class DriverOnboardingTest extends TestCase
{
    private Country $saudi;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2])->id,
        ]);
        $this->driver = Driver::query()->create(['uuid' => (string) Str::uuid(), 'phone' => '+966551234567', 'phone_verified_at' => now(), 'country_id' => $this->saudi->id])->fresh();
    }

    public function test_a_new_driver_signs_up_step_by_step_and_goes_to_review(): void
    {
        $license = $this->type('driving_license', 'driver', ['has_number' => true, 'has_expiry' => true]);
        $registration = $this->type('vehicle_registration', 'vehicle', ['has_expiry' => true]);
        $permit = $this->type('residence_permit', 'driver', ['has_expiry' => true], 'resident');

        $state = $this->actingAs($this->driver, 'driver_api')->getJson('/api/driver/v1/onboarding')->assertOk()
            ->assertJsonPath('data.steps.profile', false)
            ->assertJsonPath('data.can_submit', false)
            ->json('data');
        $this->assertSame(['driving_license', 'vehicle_registration'], array_column(array_column($state['required_documents'], 'type'), 'code'), 'a citizen never sees the residents-only permit');
        $this->postJson('/api/driver/v1/onboarding/submit')->assertUnprocessable()->assertJsonPath('error_code', 'driver_onboarding_incomplete');

        $this->putJson('/api/driver/v1/profile', ['name' => 'Ahmed Ali', 'national_id' => '1012345678', 'id_type' => 'national_id', 'date_of_birth' => '1990-05-15', 'gender' => 'male'])
            ->assertOk()->assertJsonPath('data.steps.profile', true);

        $make = VehicleMake::query()->create(['status' => true]);
        $this->postJson('/api/driver/v1/vehicles', [
            'make_id' => $make->id, 'model_id' => VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'sedan', 'seats' => 4, 'status' => true])->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#000000', 'status' => true])->id, 'year' => 2023, 'body_type' => 'sedan', 'seats' => 4,
            'plate_letters' => 'ابج', 'plate_number' => '4321', 'plate_type' => 1, 'sequence_number' => '123456789',
        ])->assertCreated()->assertJsonPath('data.vehicle.plate_letters', 'ا ب ج')->assertJsonPath('data.vehicle.status', 'pending');

        $this->upload($license, ['number' => 'L-1', 'expires_at' => now()->addYear()->toDateString()]);
        $this->upload($registration, ['expires_at' => now()->addYear()->toDateString()])
            ->assertJsonPath('data.required_documents.1.document.status', 'pending');
        // An expired document isn't accepted from the app.
        $this->post('/api/driver/v1/documents', ['document_type_id' => $license, 'number' => 'L-2', 'expires_at' => now()->subDay()->toDateString(), 'front' => UploadedFile::fake()->image('f.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('expires_at');

        $this->postJson('/api/driver/v1/onboarding/submit')->assertOk()->assertJsonPath('data.driver.status', 'under_review')->assertJsonPath('data.can_submit', false);
        $this->assertSame('under_review', $this->driver->fresh()->status->value);

        // Made a resident by the admin: the permit now shows as missing.
        $this->driver->update(['residency' => 'resident']);
        $this->getJson('/api/driver/v1/onboarding')->assertOk()->assertJsonPath('data.missing', ['document:residence_permit']);
        $this->assertNotNull($permit);
    }

    public function test_a_captain_without_a_vehicle_skips_the_vehicle_and_its_documents_and_a_plate_type_defaults_to_private(): void
    {
        // O-64: «no vehicle» (riders' own cars, lessons): neither the vehicle nor its documents are asked for.
        $this->type('driving_license', 'driver', ['has_number' => true, 'has_expiry' => true]);
        $this->type('vehicle_registration', 'vehicle', ['has_expiry' => true]);
        $state = $this->actingAs($this->driver, 'driver_api')->postJson('/api/driver/v1/onboarding/no-vehicle', ['on' => true])->assertOk()
            ->assertJsonPath('data.no_vehicle', true)->assertJsonPath('data.steps.vehicle', true)->json('data');
        $this->assertNotContains('vehicle', $state['missing']);
        $this->assertSame(['driving_license'], array_column(array_column($state['required_documents'], 'type'), 'code'));

        // With a vehicle after all: no plate type sent = private (1); the sequence number is required.
        $make = VehicleMake::query()->create(['status' => true]);
        $body = [
            'make_id' => $make->id, 'model_id' => VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'sedan', 'seats' => 4, 'status' => true])->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#000000', 'status' => true])->id, 'year' => 2023, 'body_type' => 'sedan', 'seats' => 4,
            'plate_letters' => 'ابج', 'plate_number' => '4321',
        ];
        $this->postJson('/api/driver/v1/vehicles', $body)->assertUnprocessable()->assertJsonValidationErrors('sequence_number');
        $this->postJson('/api/driver/v1/vehicles', $body + ['sequence_number' => '987654321'])->assertCreated()->assertJsonPath('data.vehicle.plate_type', 1);
    }

    public function test_the_driver_phone_registers_for_ride_request_pushes(): void
    {
        $this->actingAs($this->driver, 'driver_api')->postJson('/api/driver/v1/notifications/devices', ['player_id' => 'player-1', 'platform' => 'android'])->assertOk();

        $this->assertSame(1, $this->driver->notificationDevices()->where('player_id', 'player-1')->count());
    }

    /** @param array<string, mixed> $extra */
    private function upload(int $type, array $extra): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/driver/v1/documents', ['document_type_id' => $type, 'front' => UploadedFile::fake()->image('front.jpg'), ...$extra], ['Accept' => 'application/json'])->assertCreated();
    }

    /** @param array<string, mixed> $flags */
    private function type(string $code, string $appliesTo, array $flags, ?string $residency = null): int
    {
        static $sort = 0;
        $type = DriverDocumentType::query()->create(['code' => $code, 'applies_to' => $appliesTo, 'status' => true, 'sort_order' => ++$sort, ...$flags]);
        DriverDocumentRequirement::query()->create(['document_type_id' => $type->id, 'country_id' => $this->saudi->id, 'residency' => $residency, 'is_required' => true]);

        return $type->id;
    }
}
