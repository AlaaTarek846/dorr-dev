<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Database\Seeders\FreelanceDocumentTypeSeeder;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Models\FreelanceDocumentRequirement;
use Modules\Freelance\Models\FreelanceDocumentType;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelanceVerificationDocument as Doc;
use Modules\Freelance\Models\FreelanceVerificationRequest;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Verification\FreelancerVerification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-107 / FL-108 / FL-110: verifying a freelancer by hand — the documents a country asks for, the request, the admin's
 * decision, expiry — and the gate: only a verified freelancer may do the work.
 */
class FreelancerVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Freelancer $me;

    private FreelanceDocumentType $nationalId;

    private FreelanceDocumentType $selfie;

    private FreelanceDocumentType $residence;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        Storage::fake('public');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        FreelanceFeatureRule::query()->create(['feature' => 'freelance', 'country_id' => $this->saudi->id, 'enabled' => true]);
        FreelanceFeatures::forget();

        $this->seed(FreelanceDocumentTypeSeeder::class);
        $this->nationalId = FreelanceDocumentType::query()->where('code', 'national_id')->firstOrFail();
        $this->selfie = FreelanceDocumentType::query()->where('code', 'selfie_with_id')->firstOrFail();
        $this->residence = FreelanceDocumentType::query()->where('code', 'residence_permit')->firstOrFail();
        // Saudi Arabia asks everyone for a selfie, citizens for the national ID, residents for the residence permit.
        $this->require($this->selfie, null);
        $this->require($this->nationalId, 'citizen');
        $this->require($this->residence, 'resident');

        $this->me = $this->freelancer('+966500000077', 'profile_ready', 'citizen');

        // A route only a verified freelancer may use — what applying for a project will be (FL-301).
        Route::middleware(['api', 'auth:freelance_api', 'freelance.verified'])->post('/api/freelance/v1/_apply', fn () => response()->json(['ok' => true]));
    }

    private function require(FreelanceDocumentType $type, ?string $residency): void
    {
        FreelanceDocumentRequirement::query()->create(['document_type_id' => $type->id, 'country_id' => $this->saudi->id, 'residency' => $residency, 'is_required' => true]);
    }

    private function freelancer(string $phone, string $status, ?string $residency = null): Freelancer
    {
        $f = Freelancer::query()->create(['name' => 'Sara', 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => $status, 'residency' => $residency]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f, [], 'freelance_api');

        return $f;
    }

    private function asAdmin(array $permissions = ['freelance-verifications.view', 'freelance-verifications.update']): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    private function asMe(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->me->fresh(), [], 'freelance_api');
    }

    private function uploadNationalId(array $extra = []): array
    {
        return $this->post('/api/freelance/v1/verification/documents', $extra + [
            'document_type_id' => $this->nationalId->id, 'number' => '1012345678', 'expires_on' => now()->addYear()->toDateString(),
            'front' => UploadedFile::fake()->image('front.jpg'), 'back' => UploadedFile::fake()->image('back.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    private function uploadSelfie(): array
    {
        return $this->post('/api/freelance/v1/verification/documents', ['document_type_id' => $this->selfie->id, 'front' => UploadedFile::fake()->image('me.jpg')], ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    private function submitAll(): string
    {
        $this->uploadNationalId();
        $this->uploadSelfie();
        $this->postJson('/api/freelance/v1/verification/submit')->assertOk()->assertJsonPath('data.status', 'verification_submitted');

        return FreelanceVerificationRequest::query()->latest('id')->value('uuid');
    }

    // -------------------------------------------------------------- what is required

    public function test_the_required_documents_follow_the_country_and_the_residency(): void
    {
        $citizen = collect($this->getJson('/api/freelance/v1/verification')->assertOk()->json('data.required'))->pluck('type.code')->all();
        $this->assertEqualsCanonicalizing(['selfie_with_id', 'national_id'], $citizen);

        $this->me = $this->freelancer('+966500000078', 'profile_ready', 'resident');
        $resident = collect($this->getJson('/api/freelance/v1/verification')->json('data.required'))->pluck('type.code')->all();
        $this->assertEqualsCanonicalizing(['selfie_with_id', 'residence_permit'], $resident);

        // A switched-off type is required from nobody.
        $this->residence->update(['status' => false]);
        $this->assertSame(['selfie_with_id'], collect($this->getJson('/api/freelance/v1/verification')->json('data.required'))->pluck('type.code')->all());
    }

    public function test_without_a_residency_the_freelancer_must_say_which_before_sending(): void
    {
        $this->me = $this->freelancer('+966500000078', 'profile_ready');
        $this->getJson('/api/freelance/v1/verification')->assertJsonPath('data.needs_residency', true)->assertJsonPath('data.submit_blockers', ['residency']);
        $this->postJson('/api/freelance/v1/verification/submit')->assertUnprocessable()->assertJsonPath('error_code', 'freelance_verification_residency_required');

        $this->putJson('/api/freelance/v1/profile', ['name' => 'Sara', 'residency' => 'citizen'])->assertOk()->assertJsonPath('data.residency', 'citizen');
        $this->getJson('/api/freelance/v1/verification')->assertJsonPath('data.needs_residency', false);
    }

    // -------------------------------------------------------------- uploading

    public function test_a_document_is_uploaded_privately_and_checked_against_its_type(): void
    {
        $doc = $this->uploadNationalId();
        $this->assertSame('draft', $doc['status']);
        $this->assertSame('verification/documents/'.$doc['id'].'/front', $doc['files']['front']['path']);
        $this->assertArrayNotHasKey('url', $doc['files']['front']);

        $media = Doc::query()->find($doc['id'])->file('front');
        $this->assertSame('local', $media->disk);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing on the public disk');
        $own = $this->get("/api/freelance/v1/{$doc['files']['front']['path']}")->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $own);
        $this->assertStringNotContainsString('public', $own);

        // Its type's fields are required; a wrong file is refused.
        $this->post('/api/freelance/v1/verification/documents', ['document_type_id' => $this->nationalId->id, 'front' => UploadedFile::fake()->image('f.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'freelance_verification_document_incomplete')->assertJsonPath('data.fields', ['number', 'expires_on', 'back']);
        $this->post('/api/freelance/v1/verification/documents', ['document_type_id' => $this->selfie->id, 'front' => UploadedFile::fake()->create('x.exe', 10)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/freelance/v1/verification/documents', ['document_type_id' => $this->selfie->id, 'front' => UploadedFile::fake()->create('big.pdf', 8193, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/freelance/v1/verification/documents', ['document_type_id' => $this->nationalId->id, 'number' => '1', 'expires_on' => now()->subDay()->toDateString(), 'front' => UploadedFile::fake()->image('f.jpg'), 'back' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_uploading_the_same_type_again_replaces_the_draft_and_a_draft_can_be_deleted(): void
    {
        $first = $this->uploadNationalId();
        $second = $this->uploadNationalId(['number' => '1099999999']);

        $this->assertNull(Doc::query()->find($first['id']));
        $this->assertSame(1, Doc::query()->where('document_type_id', $this->nationalId->id)->count());
        $this->deleteJson("/api/freelance/v1/verification/documents/{$second['id']}")->assertOk();
        $this->assertSame(0, Doc::query()->count());
    }

    public function test_someone_elses_documents_are_out_of_reach(): void
    {
        $doc = $this->uploadNationalId();
        $this->freelancer('+966500000078', 'profile_ready', 'citizen');

        $this->get("/api/freelance/v1/{$doc['files']['front']['path']}")->assertNotFound();
        $this->deleteJson("/api/freelance/v1/verification/documents/{$doc['id']}")->assertNotFound();
        $this->getJson('/api/freelance/v1/verification')->assertJsonPath('data.required.0.document', null);
    }

    // -------------------------------------------------------------- sending

    public function test_the_request_waits_for_every_required_document(): void
    {
        $this->uploadNationalId();
        $this->getJson('/api/freelance/v1/verification')->assertJsonPath('data.can_submit', false)->assertJsonPath('data.submit_blockers', ['missing:selfie_with_id']);
        $this->postJson('/api/freelance/v1/verification/submit')->assertUnprocessable()->assertJsonPath('error_code', 'freelance_verification_incomplete');

        $this->uploadSelfie();
        $this->getJson('/api/freelance/v1/verification')->assertJsonPath('data.can_submit', true);
    }

    public function test_sending_locks_the_documents_until_the_decision(): void
    {
        $this->submitAll();

        $this->assertSame(['pending'], Doc::query()->pluck('status')->unique()->values()->all());
        $this->getJson('/api/freelance/v1/verification')->assertJsonPath('data.step', 'under_review')->assertJsonPath('data.can_submit', false);
        $this->post('/api/freelance/v1/verification/documents', ['document_type_id' => $this->selfie->id, 'front' => UploadedFile::fake()->image('again.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('error_code', 'freelance_verification_document_locked');
        $this->deleteJson('/api/freelance/v1/verification/documents/'.Doc::query()->value('id'))->assertStatus(409);
        $this->postJson('/api/freelance/v1/verification/submit')->assertStatus(409)->assertJsonPath('error_code', 'freelance_verification_not_allowed');
    }

    public function test_an_account_that_is_not_ready_cannot_send(): void
    {
        $this->me = $this->freelancer('+966500000078', 'registered', 'citizen');
        $this->getJson('/api/freelance/v1/verification')->assertJsonPath('data.submit_blockers', ['not_allowed'])->assertJsonPath('data.step', 'complete_profile');
        $this->postJson('/api/freelance/v1/verification/submit')->assertStatus(409);
    }

    // -------------------------------------------------------------- the admin

    public function test_the_admin_approves_and_the_freelancer_may_now_work(): void
    {
        $uuid = $this->submitAll();
        $this->postJson('/api/freelance/v1/_apply')->assertForbidden()->assertJsonPath('error_code', 'freelance_verification_required')->assertJsonPath('data.step', 'under_review');

        $this->asAdmin();
        $this->getJson('/api/admin/v1/freelance-verifications?status=submitted')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.counts.submitted', 1);
        $show = $this->getJson("/api/admin/v1/freelance-verifications/{$uuid}")->assertOk()->assertJsonCount(2, 'data.documents')->json('data');
        $this->get('/api/admin/v1/'.$show['documents'][0]['files']['front']['path'])->assertOk();
        $cache = $this->get('/api/admin/v1/'.$show['documents'][0]['files']['front']['path'])->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringNotContainsString('public', $cache);

        $this->postJson("/api/admin/v1/freelance-verifications/{$uuid}/decide", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');

        $me = $this->me->fresh();
        $this->assertSame(FreelancerStatus::Verified, $me->status);
        $this->assertNotNull($me->verified_at);
        $this->assertSame(['approved'], Doc::query()->pluck('status')->unique()->values()->all());
        $this->assertSame('freelance.verification.approved', $me->notifications()->first()->data['event']);

        $this->asMe();
        $this->postJson('/api/freelance/v1/_apply')->assertOk();
        $this->getJson('/api/freelance/v1/auth/me')->assertJsonPath('data.can_apply', true)->assertJsonPath('data.step', null);
    }

    public function test_rejecting_needs_a_reason_and_the_freelancer_can_try_again(): void
    {
        $uuid = $this->submitAll();
        $this->asAdmin();
        $this->postJson("/api/admin/v1/freelance-verifications/{$uuid}/decide", ['decision' => 'reject'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_verification_reason_required');
        $this->postJson("/api/admin/v1/freelance-verifications/{$uuid}/decide", ['decision' => 'reject', 'reason' => 'The photo is blurry'])->assertOk()->assertJsonPath('data.reason', 'The photo is blurry');
        $this->postJson("/api/admin/v1/freelance-verifications/{$uuid}/decide", ['decision' => 'approve'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_verification_already_decided');

        $me = $this->me->fresh();
        $this->assertSame(FreelancerStatus::VerificationRejected, $me->status);
        $this->assertSame('The photo is blurry', $me->status_reason);
        $this->assertSame(['reason' => 'The photo is blurry'], array_intersect_key($me->notifications()->first()->data['variables'], ['reason' => 1]));

        // Rejected documents must be uploaded again before a new request; the old request stays on record.
        $this->asMe();
        $this->getJson('/api/freelance/v1/verification')->assertJsonPath('data.step', 'submit_verification')->assertJsonPath('data.last_request.status', 'rejected')->assertJsonPath('data.can_submit', false);
        $this->submitAll();
        $this->assertSame(2, FreelanceVerificationRequest::query()->count());
    }

    public function test_asking_for_changes_keeps_the_good_documents_and_rejects_the_rest(): void
    {
        $uuid = $this->submitAll();
        $selfie = Doc::query()->where('document_type_id', $this->selfie->id)->value('id');
        $id = Doc::query()->where('document_type_id', $this->nationalId->id)->value('id');

        $this->asAdmin();
        $this->postJson("/api/admin/v1/freelance-verifications/{$uuid}/decide", [
            'decision' => 'changes', 'reason' => 'Upload the back of the ID again',
            'documents' => [$selfie => ['status' => 'approved'], $id => ['status' => 'rejected', 'reason' => 'Back side unreadable']],
        ])->assertOk()->assertJsonPath('data.status', 'changes_requested');

        $this->assertSame('approved', Doc::query()->find($selfie)->status);
        $this->assertSame('Back side unreadable', Doc::query()->find($id)->rejection_reason);
        $this->assertSame(FreelancerStatus::ChangesRequested, $this->me->fresh()->status);

        // Only the rejected one has to be sent again; the approved selfie still counts.
        $this->asMe();
        $this->getJson('/api/freelance/v1/verification')->assertJsonPath('data.step', 'fix_documents')->assertJsonPath('data.submit_blockers', ['missing:national_id']);
        $this->uploadNationalId();
        $this->postJson('/api/freelance/v1/verification/submit')->assertOk();
        $uuid2 = FreelanceVerificationRequest::query()->latest('id')->value('uuid');
        $this->asAdmin();
        $this->postJson("/api/admin/v1/freelance-verifications/{$uuid2}/decide", ['decision' => 'approve'])->assertOk();
        $this->assertSame(FreelancerStatus::Verified, $this->me->fresh()->status);
    }

    public function test_approving_while_a_document_is_rejected_one_by_one_is_refused(): void
    {
        $uuid = $this->submitAll();
        $id = Doc::query()->where('document_type_id', $this->nationalId->id)->value('id');
        $this->asAdmin();

        $this->postJson("/api/admin/v1/freelance-verifications/{$uuid}/decide", ['decision' => 'approve', 'documents' => [$id => ['status' => 'rejected', 'reason' => 'Fake']]])
            ->assertUnprocessable()->assertJsonPath('error_code', 'freelance_verification_incomplete');
        $this->assertSame('submitted', FreelanceVerificationRequest::query()->value('status'), 'nothing was saved');
        $this->assertSame(['pending'], Doc::query()->pluck('status')->unique()->values()->all());
    }

    public function test_the_admin_screens_need_their_permissions(): void
    {
        $uuid = $this->submitAll();
        $id = Doc::query()->value('id');

        $this->asAdmin(['freelance-verifications.view']);
        $this->getJson("/api/admin/v1/freelance-verifications/{$uuid}")->assertOk();
        $this->postJson("/api/admin/v1/freelance-verifications/{$uuid}/decide", ['decision' => 'approve'])->assertForbidden();

        $this->asAdmin([]);
        $this->getJson('/api/admin/v1/freelance-verifications')->assertForbidden();
        $this->get("/api/admin/v1/freelance-verifications/documents/{$id}/front")->assertForbidden();
        $this->getJson('/api/admin/v1/freelance-document-types')->assertForbidden();
    }

    public function test_the_admin_manages_document_types_and_where_they_are_required(): void
    {
        $this->asAdmin(['freelance-document-types.view', 'freelance-document-types.create', 'freelance-document-types.update', 'freelance-document-types.delete']);

        $created = $this->postJson('/api/admin/v1/freelance-document-types', [
            'code' => 'driving_license', 'has_number' => true, 'has_expiry' => true, 'needs_back_side' => false, 'status' => true,
            'name' => ['ar' => 'رخصة القيادة', 'en' => 'Driving licence'],
            'requirements' => [['country_id' => $this->saudi->id, 'residency' => 'resident']],
        ])->assertCreated()->assertJsonPath('data.names.ar', 'رخصة القيادة')->assertJsonPath('data.requirements.0.residency', 'resident')->json('data');

        $this->postJson('/api/admin/v1/freelance-document-types', ['code' => 'Bad Code', 'name' => ['ar' => 'x', 'en' => 'x']])->assertUnprocessable();
        $this->patchJson("/api/admin/v1/freelance-document-types/{$created['id']}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);
        $this->deleteJson("/api/admin/v1/freelance-document-types/{$created['id']}")->assertOk();

        // A type with uploads stays: switch it off instead.
        $this->asMe();
        $this->uploadSelfie();
        $this->asAdmin(['freelance-document-types.delete']);
        $this->deleteJson("/api/admin/v1/freelance-document-types/{$this->selfie->id}")->assertStatus(409)->assertJsonPath('error_code', 'freelance_document_type_in_use');
    }

    // -------------------------------------------------------------- the Word guides

    private function docx($response): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }

    public function test_the_document_types_guide_lists_every_type_and_what_the_country_requires(): void
    {
        $this->asAdmin(['freelance-document-types.view']);

        $xml = $this->docx($this->get('/api/admin/v1/freelance-document-types/guide?country_id='.$this->saudi->id)->assertOk());

        foreach (['national_id', 'residence_permit', 'passport', 'selfie_with_id'] as $code) {
            $this->assertStringContainsString($code, $xml);
        }
        $this->assertStringContainsString('مستندات التوثيق', $xml);
        $this->assertStringContainsString('المواطنين', $xml, 'the national ID is for citizens');
        $this->assertStringContainsString('المقيمين', $xml, 'the residence permit is for residents');
        $this->assertStringContainsString('مش مطلوب', $xml, 'the passport is required nowhere');

        $this->getJson('/api/admin/v1/freelance-document-types/guide')->assertUnprocessable();
        $this->asAdmin([]);
        $this->getJson('/api/admin/v1/freelance-document-types/guide?country_id='.$this->saudi->id)->assertForbidden();
    }

    public function test_the_requests_guide_explains_every_status_and_the_queue_counts(): void
    {
        $this->submitAll();
        $this->asAdmin(['freelance-verifications.view']);

        $xml = $this->docx($this->get('/api/admin/v1/freelance-verifications/guide')->assertOk());

        foreach (FreelancerStatus::cases() as $case) {
            $this->assertStringContainsString($case->value, $xml);
        }
        $this->assertStringContainsString('طلبات التوثيق', $xml);
        $this->assertStringContainsString('بانتظار المراجعة: 1', $xml);
        $this->assertStringContainsString('البوابة', $xml);

        $this->asAdmin([]);
        $this->getJson('/api/admin/v1/freelance-verifications/guide')->assertForbidden();
    }

    // -------------------------------------------------------------- expiry

    public function test_an_expired_document_sends_a_verified_freelancer_back_to_fixing_it(): void
    {
        $uuid = $this->submitAll();
        $this->asAdmin();
        $this->postJson("/api/admin/v1/freelance-verifications/{$uuid}/decide", ['decision' => 'approve'])->assertOk();

        $this->travel(2)->years();
        $this->artisan('freelance:expire-documents')->assertSuccessful();

        $this->assertSame('expired', Doc::query()->where('document_type_id', $this->nationalId->id)->value('status'));
        $this->assertSame('approved', Doc::query()->where('document_type_id', $this->selfie->id)->value('status'), 'a document without a date never expires');
        $me = $this->me->fresh();
        $this->assertSame(FreelancerStatus::ChangesRequested, $me->status);
        $this->assertSame('freelance.verification.changes_requested', $me->notifications()->latest()->first()->data['event']);

        $this->asMe();
        $this->postJson('/api/freelance/v1/_apply')->assertForbidden()->assertJsonPath('data.step', 'fix_documents');
    }

    // -------------------------------------------------------------- the gate

    public function test_the_gate_names_the_missing_step_for_every_state(): void
    {
        foreach ([
            'registered' => 'complete_profile', 'profile_ready' => 'submit_verification', 'verification_submitted' => 'under_review',
            'verification_rejected' => 'submit_verification', 'changes_requested' => 'fix_documents', 'suspended' => 'suspended', 'verified' => null,
        ] as $status => $step) {
            $this->me->forceFill(['status' => $status])->save();
            $this->asMe();
            $this->getJson('/api/freelance/v1/auth/me')->assertOk()->assertJsonPath('data.step', $step)->assertJsonPath('data.can_apply', $step === null);
            $response = $this->postJson('/api/freelance/v1/_apply');
            $step === null ? $response->assertOk() : $response->assertForbidden()->assertJsonPath('data.step', $step);
        }
    }

    public function test_browsing_and_the_profile_stay_open_before_verification(): void
    {
        $this->me->forceFill(['status' => 'registered'])->save();
        $this->asMe();

        $this->getJson('/api/freelance/v1/auth/me')->assertOk();
        $this->getJson('/api/freelance/v1/config')->assertOk();
        $this->getJson('/api/freelance/v1/specialties')->assertOk();
        $this->getJson('/api/freelance/v1/skills')->assertOk();
        $this->putJson('/api/freelance/v1/profile', ['name' => 'Sara'])->assertOk();
        $this->getJson('/api/freelance/v1/notifications')->assertOk();
        $this->getJson('/api/freelance/v1/verification')->assertOk();
    }

    public function test_an_old_status_name_was_renamed(): void
    {
        $this->assertSame([], array_intersect(['incomplete', 'pending_verification', 'active'], array_column(FreelancerStatus::cases(), 'value')));
        $this->assertTrue(app(FreelancerVerification::class) instanceof FreelancerVerification);
    }
}
