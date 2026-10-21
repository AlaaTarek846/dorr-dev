<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\ServiceCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverDocumentType;
use Modules\Driver\Models\VehicleColor;
use Modules\Driver\Models\VehicleMake;
use Modules\Driver\Models\VehicleModel;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Admin → Drivers (docs/rides-plan.md §11.3): a driver registered from the dashboard as a citizen or
 * a resident, their vehicle and documents, the document types with their requirements, and the
 * account status — and that the work gate follows exactly that.
 */
class DriverAdminTest extends TestCase
{
    private Country $egypt;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['broadcasting.default' => 'null']);
        $this->egypt = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'eg'])->id,
            'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2])->id,
        ]);
    }

    public function test_an_admin_registers_a_resident_and_the_documents_for_residents_decide_if_they_work(): void
    {
        $this->asAdmin(['drivers.view', 'drivers.create', 'drivers.update', 'driver-document-types.view', 'driver-document-types.create', 'driver-document-types.update']);

        // Two document types: one for everyone, one for residents only.
        $license = $this->postJson('/api/admin/v1/driver-document-types', [
            'code' => 'driving_license', 'applies_to' => 'driver', 'has_number' => true, 'has_expiry' => true, 'status' => true,
            'name' => ['ar' => 'رخصة القيادة', 'en' => 'Driving licence'],
            'requirements' => [['country_id' => $this->egypt->id]],
        ])->assertCreated()->assertJsonPath('data.names.ar', 'رخصة القيادة')->json('data.id');
        $permit = $this->postJson('/api/admin/v1/driver-document-types', [
            'code' => 'residence_permit', 'applies_to' => 'driver', 'has_expiry' => true, 'status' => true,
            'name' => ['ar' => 'تصريح الإقامة', 'en' => 'Residence permit'],
            'requirements' => [['country_id' => $this->egypt->id, 'residency' => 'resident']],
        ])->assertCreated()->assertJsonPath('data.requirements.0.residency', 'resident')->json('data.id');

        $driver = $this->postJson('/api/admin/v1/drivers', [
            'name' => 'Ali Hassan', 'phone' => '01012345678', 'country_id' => $this->egypt->id, 'residency' => 'resident',
            'national_id' => '29001011234567', 'id_type' => 'passport', 'date_of_birth' => '1990-01-01',
        ])->assertCreated()
            ->assertJsonPath('data.phone', '+201012345678')
            ->assertJsonPath('data.residency', 'resident')
            ->assertJsonPath('data.registered_by', 'admin')
            ->assertJsonPath('data.status', 'pending_documents')
            ->json('data');
        $this->postJson('/api/admin/v1/drivers', ['name' => 'X', 'phone' => '1012345678', 'country_id' => $this->egypt->id, 'residency' => 'citizen'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');

        // A vehicle, approved: it becomes the active one.
        $vehicle = $this->postJson("/api/admin/v1/drivers/{$driver['id']}/vehicles", $this->vehicle())->assertCreated()->json('data.vehicles.0');
        $this->patchJson("/api/admin/v1/drivers/{$driver['id']}/vehicles/{$vehicle['id']}/status", ['status' => 'approved'])->assertOk()
            ->assertJsonPath('data.vehicles.0.is_active', true);
        $this->patchJson("/api/admin/v1/drivers/{$driver['id']}/status", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
        // O-66: the captain's app hears both at once (it reloads on `driver.*`).
        $this->assertEqualsCanonicalizing(['driver.vehicle', 'driver.status'], Driver::query()->findOrFail($driver['id'])->notifications()->get()->map(fn ($n) => $n->data['event'] ?? null)->all());

        $gate = app(DriverEligibility::class);
        $model = Driver::query()->findOrFail($driver['id']);
        $this->assertSame('documents_missing', $gate->reasonNotToWork($model));

        $this->upload($driver['id'], $license, ['number' => 'L-1', 'expires_at' => now()->addYear()->toDateString()]);
        $this->assertSame('documents_missing', $gate->reasonNotToWork($model->fresh()), 'a resident also needs the residents-only permit');
        $this->upload($driver['id'], $permit, ['expires_at' => now()->addYear()->toDateString()])
            ->assertJsonPath('data.documents.0.status', 'approved')
            ->assertJsonPath('data.documents.0.front_url', fn ($url) => is_string($url) && $url !== '');
        $this->assertNull($gate->reasonNotToWork($model->fresh()), 'Egypt has no regulator: documents + active vehicle + active account');

        // The permit is switched off as a type: nobody needs it any more.
        $this->patchJson("/api/admin/v1/driver-document-types/{$permit}/status", ['status' => false])->assertOk();
        $this->deleteJson("/api/admin/v1/driver-document-types/{$permit}")->assertForbidden();
    }

    public function test_a_rejected_document_shows_its_reason_and_a_newer_approval_replaces_the_old_one(): void
    {
        $this->asAdmin(['drivers.view', 'drivers.create', 'drivers.update']);
        $type = DriverDocumentType::query()->create(['code' => 'driving_license', 'applies_to' => 'driver', 'has_expiry' => true, 'status' => true]);
        $driver = $this->postJson('/api/admin/v1/drivers', ['name' => 'Omar', 'phone' => '1012345679', 'country_id' => $this->egypt->id, 'residency' => 'citizen'])->json('data');

        $first = $this->upload($driver['id'], $type->id, ['expires_at' => now()->addYear()->toDateString()])->json('data.documents.0.id');
        $pending = $this->upload($driver['id'], $type->id, ['expires_at' => now()->addYears(2)->toDateString(), 'approve' => 0])
            ->assertJsonPath('data.documents.0.status', 'pending')->json('data.documents.0.id');

        $this->patchJson("/api/admin/v1/drivers/{$driver['id']}/documents/{$pending}/review", ['approve' => false])->assertUnprocessable();
        $this->patchJson("/api/admin/v1/drivers/{$driver['id']}/documents/{$pending}/review", ['approve' => false, 'reason' => 'صورة غير واضحة'])->assertOk()
            ->assertJsonPath('data.documents.0.status', 'rejected')->assertJsonPath('data.documents.0.rejection_reason', 'صورة غير واضحة');
        $this->patchJson("/api/admin/v1/drivers/{$driver['id']}/documents/{$pending}/review", ['approve' => true])->assertOk();

        $documents = collect($this->getJson("/api/admin/v1/drivers/{$driver['id']}")->json('data.documents'))->keyBy('id');
        $this->assertSame('approved', $documents[$pending]['status']);
        $this->assertSame('replaced', $documents[$first]['status']);
    }

    public function test_blocking_a_driver_signs_them_out_and_the_list_filters(): void
    {
        $this->asAdmin(['drivers.view', 'drivers.create', 'drivers.update']);
        $resident = $this->postJson('/api/admin/v1/drivers', ['name' => 'Resident', 'phone' => '1011111111', 'country_id' => $this->egypt->id, 'residency' => 'resident'])->json('data.id');
        $this->postJson('/api/admin/v1/drivers', ['name' => 'Citizen', 'phone' => '1022222222', 'country_id' => $this->egypt->id, 'residency' => 'citizen']);

        $this->getJson('/api/admin/v1/drivers?residency=resident')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Resident');
        $this->getJson('/api/admin/v1/drivers?search=1022')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Citizen');

        Driver::query()->findOrFail($resident)->createToken('driver');
        $this->patchJson("/api/admin/v1/drivers/{$resident}/status", ['status' => 'blocked'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson("/api/admin/v1/drivers/{$resident}/status", ['status' => 'blocked', 'reason' => 'fraud'])->assertOk()->assertJsonPath('data.status_reason', 'fraud');
        $this->assertSame(0, Driver::query()->findOrFail($resident)->tokens()->count());
    }

    public function test_without_the_permission_nothing_is_shown(): void
    {
        $this->asAdmin([]);
        $this->getJson('/api/admin/v1/drivers')->assertForbidden();
        $this->getJson('/api/admin/v1/driver-document-types')->assertForbidden();
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $extra */
    private function upload(int $driver, int $type, array $extra): \Illuminate\Testing\TestResponse
    {
        return $this->post("/api/admin/v1/drivers/{$driver}/documents", [
            'document_type_id' => $type,
            'front' => UploadedFile::fake()->image('front.jpg'),
            ...$extra,
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    /** @return array<string, mixed> */
    private function vehicle(): array
    {
        $make = VehicleMake::query()->create(['status' => true]);

        return [
            'make_id' => $make->id,
            'model_id' => VehicleModel::query()->create(['make_id' => $make->id, 'body_type' => 'sedan', 'seats' => 4, 'status' => true])->id,
            'color_id' => VehicleColor::query()->create(['hex' => '#FFFFFF', 'status' => true])->id,
            'year' => 2022, 'body_type' => 'sedan', 'seats' => 4, 'plate_letters' => 'أ ب ج', 'plate_number' => '1234', 'plate_type' => 1, 'sequence_number' => (string) random_int(100000000, 999999999),
        ];
    }

    /** @param list<string> $permissions */
    private function asAdmin(array $permissions): void
    {
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }
}
