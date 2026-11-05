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
use Modules\Provider\Enums\DocumentStatus;
use Modules\Provider\Enums\MembershipStatus;
use Modules\Provider\Models\DocumentRequirement;
use Modules\Provider\Models\DocumentType;
use Modules\Provider\Models\Establishment;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Provider;
use Modules\Provider\Models\ProviderDocument;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Models\VehicleType;
use Modules\Provider\Services\Documents\DocumentService;
use Modules\Provider\Services\Establishments\EstablishmentService;
use Modules\Provider\Services\Establishments\WorkGate;
use Modules\Provider\Support\ProviderPermissions;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase A of docs/moving_tasks.md: phone sign-in for the providers app, one establishment per owner with its main
 * branch, the team with permissions (§17), vehicles with the plate photo and papers reviewed by the admin before any
 * offer (§6), exclusivity (§14) and the expiry sweep.
 */
class ProviderPlatformTest extends TestCase
{
    private Country $egypt;

    private ServiceCategory $moving;

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
        $this->moving = ServiceCategory::query()->create([
            'module_name' => 'moving', 'audiences' => ['admin', 'user', 'provider'], 'requires_provider' => true, 'status' => true, 'sort_order' => 1,
        ]);

        // The defaults migration ran before this country and service existed: point them here.
        foreach (VehicleType::query()->get() as $type) {
            $type->countries()->sync([$this->egypt->id]);
            $type->services()->sync([$this->moving->id]);
        }
        foreach (DocumentType::query()->get() as $type) {
            DocumentRequirement::query()->create([
                'document_type_id' => $type->id, 'country_id' => $this->egypt->id,
                'service_category_id' => $type->subject->value === 'establishment' ? null : $this->moving->id,
                'establishment_type' => match ($type->code) {
                    'national_id' => 'individual', 'commercial_register' => 'company', default => null
                },
            ]);
        }
    }

    public function test_phone_sign_in_makes_an_account_and_picks_up_invitations_sent_to_that_phone(): void
    {
        $owner = $this->owner('1011111111');
        $ownerSeat = $this->establishmentOf($owner, 'company');
        $this->actingAsProvider($owner);
        $this->postJson('/api/provider/v1/members', ['dial_code' => '+20', 'phone' => '1022222222', 'permissions' => ['requests.view', 'offers.send']])
            ->assertCreated()->assertJsonPath('data.status', 'invited');
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/provider/v1/auth/otp', ['dial_code' => '+20', 'phone' => '1022222222'])
            ->assertOk()->assertJsonPath('data.is_new_provider', true);
        $token = $this->postJson('/api/provider/v1/auth/verify', ['dial_code' => '+20', 'phone' => '1022222222', 'code' => '1234', 'platform' => 'android'])
            ->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/provider/v1/workspace')
            ->assertOk()
            ->assertJsonCount(0, 'data.memberships')
            ->assertJsonPath('data.invitations.0.establishment.uuid', $ownerSeat->establishment->uuid);
    }

    public function test_an_owner_registers_one_establishment_with_its_main_branch_and_every_permission(): void
    {
        $owner = $this->owner();
        $this->actingAsProvider($owner);

        $this->getJson('/api/provider/v1/workspace/options')->assertOk()->assertJsonPath('data.services.0.module_name', 'moving');

        $this->postJson('/api/provider/v1/establishments', [
            'type' => 'individual', 'name' => 'أبو يوسف للنقل', 'country_id' => $this->egypt->id,
            'service_ids' => [$this->moving->id], 'branch' => ['lat' => 30.05, 'lng' => 31.33, 'radius_km' => 15],
        ])->assertCreated()->assertJsonPath('data.is_owner', true);

        $this->getJson('/api/provider/v1/establishment')
            ->assertOk()
            ->assertJsonPath('data.establishment.status', 'draft')
            ->assertJsonPath('data.establishment.branches.0.is_main', true)
            ->assertJsonPath('data.membership.permissions', ProviderPermissions::all());

        // One person owns one establishment.
        $this->postJson('/api/provider/v1/establishments', [
            'type' => 'company', 'name' => 'X', 'country_id' => $this->egypt->id, 'commercial_register_no' => '1',
            'service_ids' => [$this->moving->id], 'branch' => ['lat' => 30, 'lng' => 31],
        ])->assertStatus(422)->assertJsonPath('error_code', 'provider_already_owns');

        // The individual's ID is needed before review.
        $this->postJson('/api/provider/v1/establishment/submit')->assertStatus(422)->assertJsonPath('error_code', 'provider_documents_missing');
        $id = DocumentType::query()->where('code', 'national_id')->value('id');
        $this->post('/api/provider/v1/documents', [
            'subject' => 'establishment', 'document_type_id' => $id, 'number' => '29001011234567',
            'front' => UploadedFile::fake()->image('front.jpg'), 'back' => UploadedFile::fake()->image('back.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/provider/v1/establishment/submit')->assertOk()->assertJsonPath('data.status', 'under_review');
    }

    public function test_nobody_hands_out_a_permission_or_branch_they_do_not_hold_and_members_leave_whenever_they_like(): void
    {
        $owner = $this->owner();
        $seat = $this->establishmentOf($owner, 'company');
        $establishment = $seat->establishment;
        $second = $establishment->branches()->create(['name' => 'أكتوبر', 'lat' => 29.9, 'lng' => 30.9]);
        $this->actingAsProvider($owner);

        // A branch manager for the second branch only, who may manage the team but not the money.
        $managerPerson = Provider::query()->create(['phone' => '+201033333333', 'country_id' => $this->egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $managerUuid = $this->postJson('/api/provider/v1/members', [
            'dial_code' => '+20', 'phone' => '1033333333',
            'permissions' => ['requests.view', 'offers.send', 'members.manage'], 'branch_ids' => [$second->id],
        ])->assertCreated()->json('data.uuid');
        $this->app['auth']->forgetGuards();
        $this->actingAsProvider($managerPerson);
        $this->postJson("/api/provider/v1/invitations/{$managerUuid}/accept")->assertOk();
        $headers = ['X-Establishment' => $establishment->uuid];

        $this->postJson('/api/provider/v1/members', ['dial_code' => '+20', 'phone' => '1044444444', 'permissions' => ['wallet.withdraw'], 'branch_ids' => [$second->id]], $headers)
            ->assertForbidden()->assertJsonPath('error_code', 'provider_permission_not_held');
        $this->postJson('/api/provider/v1/members', ['dial_code' => '+20', 'phone' => '1044444444', 'permissions' => ['offers.send']], $headers)
            ->assertForbidden()->assertJsonPath('error_code', 'provider_branch_not_held');
        $this->postJson('/api/provider/v1/members', ['dial_code' => '+20', 'phone' => '1044444444', 'permissions' => ['offers.send'], 'branch_ids' => [$second->id]], $headers)
            ->assertCreated();
        // The owner's seat is out of reach.
        $this->putJson("/api/provider/v1/members/{$seat->uuid}", ['permissions' => []], $headers)->assertForbidden();

        // Leaving needs nobody's approval.
        $this->postJson("/api/provider/v1/memberships/{$managerUuid}/leave")->assertOk();
        $this->assertSame(MembershipStatus::Left, Membership::query()->where('uuid', $managerUuid)->firstOrFail()->status);
        $this->assertDatabaseHas('provider_membership_events', ['membership_id' => Membership::query()->where('uuid', $managerUuid)->value('id'), 'action' => 'left']);
    }

    public function test_no_offer_before_the_admin_approves_the_establishment_the_vehicle_its_plate_photo_papers_and_the_driver(): void
    {
        $owner = $this->owner();
        $seat = $this->establishmentOf($owner, 'individual');
        $gate = app(WorkGate::class);
        $this->actingAsProvider($owner);

        $vehicleUuid = $this->postJson('/api/provider/v1/vehicles', [
            'vehicle_type_id' => VehicleType::query()->where('code', 'half_truck')->value('id'),
            'plate_letters' => 'ن ق ل', 'plate_number' => '1234',
        ])->assertCreated()
            ->assertJsonPath('data.vehicle.status', 'pending')
            ->assertJsonFragment(['code' => DocumentType::PLATE_PHOTO])
            ->json('data.vehicle.uuid');
        $vehicle = Vehicle::query()->where('uuid', $vehicleUuid)->firstOrFail();

        $this->assertSame('establishment_not_approved', $gate->offerProblem($seat->fresh(), $vehicle, $this->moving->id));
        $seat->establishment->update(['status' => 'approved']);
        $this->assertSame('vehicle_not_approved', $gate->offerProblem($seat->fresh(), $vehicle->fresh(), $this->moving->id));

        $admin = $this->admin(['provider-establishments.view', 'provider-establishments.update']);
        $this->patchJson("/api/admin/v1/provider-establishments/{$seat->establishment->uuid}/vehicles/{$vehicleUuid}/review", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('vehicle_documents', $gate->offerProblem($seat->fresh(), $vehicle->fresh(), $this->moving->id));

        // Every vehicle photo and paper, approved.
        foreach (DocumentType::query()->where('subject', 'vehicle')->get() as $type) {
            $this->approvedDocument($seat->establishment, 'vehicle', $vehicle->id, $type, $admin);
        }
        $this->assertSame('driver_licence', $gate->offerProblem($seat->fresh(), $vehicle->fresh(), $this->moving->id));
        $this->approvedDocument($seat->establishment, 'membership', $seat->id, DocumentType::query()->where('code', 'driving_licence')->firstOrFail(), $admin);
        $this->assertNull($gate->offerProblem($seat->fresh(), $vehicle->fresh(), $this->moving->id));

        // Editing the plate sends it back to review.
        $this->app['auth']->forgetGuards();
        $this->actingAsProvider($owner);
        $this->putJson("/api/provider/v1/vehicles/{$vehicleUuid}", ['plate_number' => '9999'])->assertOk()->assertJsonPath('data.vehicle.status', 'pending');
    }

    public function test_an_owner_bound_exclusively_to_another_company_sends_no_offers_from_their_own(): void
    {
        $owner = $this->owner();
        $seat = $this->establishmentOf($owner, 'individual');
        $company = $this->establishmentOf($this->owner('1055555555'), 'company')->establishment;
        Membership::query()->create(['establishment_id' => $company->id, 'provider_id' => $owner->id, 'phone' => $owner->phone, 'is_exclusive' => true, 'status' => 'active', 'joined_at' => now()]);
        $seat->establishment->update(['status' => 'approved']);
        $vehicle = $seat->establishment->vehicles()->create(['vehicle_type_id' => VehicleType::query()->value('id'), 'country_id' => $this->egypt->id, 'plate_number' => '1', 'status' => 'approved']);
        $admin = $this->admin([]);
        foreach (DocumentType::query()->where('subject', 'vehicle')->get() as $type) {
            $this->approvedDocument($seat->establishment, 'vehicle', $vehicle->id, $type, $admin);
        }

        $this->assertSame('exclusive_elsewhere', app(WorkGate::class)->offerProblem($seat->fresh(), $vehicle, $this->moving->id));
    }

    public function test_the_sweep_expires_ended_papers_and_warns_once_before_the_end(): void
    {
        $seat = $this->establishmentOf($this->owner(), 'individual');
        $licence = DocumentType::query()->where('code', 'driving_licence')->firstOrFail();
        $ended = ProviderDocument::query()->create(['establishment_id' => $seat->establishment_id, 'subject_type' => 'membership', 'subject_id' => $seat->id, 'document_type_id' => $licence->id, 'expires_on' => now()->subDay(), 'status' => 'approved']);
        $soon = ProviderDocument::query()->create(['establishment_id' => $seat->establishment_id, 'subject_type' => 'membership', 'subject_id' => $seat->id, 'document_type_id' => $licence->id, 'expires_on' => now()->addDays(10), 'status' => 'approved']);

        $this->assertSame(['expired' => 1, 'warned' => 1], app(DocumentService::class)->sweep());
        $this->assertSame(DocumentStatus::Expired, $ended->fresh()->status);
        $this->assertNotNull($soon->fresh()->expiry_alerted_at);
        $this->assertSame(['expired' => 0, 'warned' => 0], app(DocumentService::class)->sweep());
    }

    public function test_the_admin_reviews_the_queue_and_the_plate_photo_cannot_be_removed(): void
    {
        $seat = $this->establishmentOf($this->owner(), 'company');
        $seat->establishment->update(['status' => 'under_review', 'submitted_at' => now()]);
        $this->admin(['provider-establishments.view', 'provider-establishments.update', 'provider-document-types.view', 'provider-document-types.delete']);

        $this->getJson('/api/admin/v1/provider-establishments?needs_review=1')->assertOk()->assertJsonPath('data.0.uuid', $seat->establishment->uuid);
        $this->getJson("/api/admin/v1/provider-establishments/{$seat->establishment->uuid}")->assertOk()->assertJsonPath('data.members.0.is_owner', true);
        $this->patchJson("/api/admin/v1/provider-establishments/{$seat->establishment->uuid}/status", ['status' => 'rejected'])->assertUnprocessable();
        $this->patchJson("/api/admin/v1/provider-establishments/{$seat->establishment->uuid}/status", ['status' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');

        $plate = DocumentType::query()->where('code', DocumentType::PLATE_PHOTO)->value('id');
        $this->deleteJson("/api/admin/v1/provider-document-types/{$plate}")->assertStatus(409);
    }

    public function test_the_wallet_screens_show_the_establishments_money_to_who_may_see_it(): void
    {
        $owner = $this->owner();
        $seat = $this->establishmentOf($owner, 'company');
        $establishment = $seat->establishment;
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->firstOrCreateWallet($establishment, $this->egypt), 50000, WalletBucket::Withdrawable, WalletTransactionType::ServiceEarning, ['idempotency_key' => 'test:est']);
        $headers = ['X-Establishment' => $establishment->uuid, 'X-Country' => 'EG'];

        $this->actingAsProvider($owner);
        $this->getJson('/api/provider/v1/wallet', $headers)->assertOk()->assertJsonPath('data.withdrawable_minor', 50000);
        // Without the header (the web portal) it is the person's own wallet.
        $this->getJson('/api/provider/v1/wallet', ['X-Country' => 'EG'])->assertOk()->assertJsonPath('data.total_minor', 0);

        // A driver of the company does not see the company's money.
        $driver = Provider::query()->create(['phone' => '+201066666666', 'country_id' => $this->egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);
        Membership::query()->create(['establishment_id' => $establishment->id, 'provider_id' => $driver->id, 'status' => 'active', 'joined_at' => now()])
            ->permissionRows()->create(['permission' => 'driving.work']);
        $this->app['auth']->forgetGuards();
        $this->actingAsProvider($driver);
        $this->getJson('/api/provider/v1/wallet', $headers)->assertForbidden()->assertJsonPath('error_code', 'provider_forbidden');
    }

    private function owner(string $phone = '1012345678'): Provider
    {
        return Provider::query()->create(['phone' => '+20'.$phone, 'country_id' => $this->egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function establishmentOf(Provider $owner, string $type): Membership
    {
        return app(EstablishmentService::class)->create($owner, [
            'type' => $type, 'name' => 'منشأة '.$owner->id, 'country_id' => $this->egypt->id, 'commercial_register_no' => $type === 'company' ? '123' : null,
            'service_ids' => [$this->moving->id], 'branch' => ['lat' => 30.05, 'lng' => 31.33, 'radius_km' => 15],
        ])->load('establishment');
    }

    private function approvedDocument(Establishment $establishment, string $subjectType, int $subjectId, DocumentType $type, Admin $admin): void
    {
        $document = ProviderDocument::query()->create([
            'establishment_id' => $establishment->id, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'document_type_id' => $type->id, 'expires_on' => $type->has_expiry ? now()->addYear() : null, 'status' => 'pending',
        ]);
        app(DocumentService::class)->approve($document, $admin->id);
    }

    private function actingAsProvider(Provider $provider): void
    {
        Sanctum::actingAs($provider, [], 'provider_api');
    }

    /** @param list<string> $permissions */
    private function admin(array $permissions): Admin
    {
        $admin = Admin::query()->firstOrCreate(['email' => 'a@example.com'], ['name' => 'A', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }
}
