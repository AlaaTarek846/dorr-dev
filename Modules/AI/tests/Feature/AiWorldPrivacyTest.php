<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Exceptions\AiWorldException;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\World\AiWorldIdentityProfile;
use Modules\AI\Models\World\AiWorldPrice;
use Modules\AI\Models\World\AiWorldProject;
use Modules\AI\Models\World\AiWorldReport;
use Modules\AI\Services\Sites\AiSiteEntitlementService;
use Modules\AI\Services\World\AiWorldPrivacyService;
use Modules\AI\Services\World\AiWorldProjectService;
use Modules\User\Models\User;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/** DORR AI WORLD Phase 4: reference photos, explicit consent, deletion and retention. */
class AiWorldPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $this->country = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => $currency->id,
        ]);
        app()->instance('resolved_country', $this->country);
        AiWorldPrice::create(['country_id' => $this->country->id, 'quality' => 'standard', 'price_per_second_minor' => 100]);
        $plan = AiPlan::query()->create(['name' => 'P', 'code' => 'p-'.uniqid(), 'usage_minutes' => 60, 'cooldown_minutes' => 0, 'duration_days' => 30, 'price' => 0, 'is_active' => true, 'video_daily_limit' => 10, 'video_max_seconds' => 8]);
        $this->mock(AiSiteEntitlementService::class, fn ($m) => $m->shouldReceive('activePlanFor')->andReturn($plan));
    }

    private function user(): User
    {
        $user = User::query()->create(['name' => 'P', 'email' => 'p-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active]);
        Sanctum::actingAs($user, ['*'], 'user_api');
        app(WalletService::class)->firstOrCreateWallet($user, $this->country)->update(['spend_only_minor' => 100000, 'withdrawable_minor' => 0]);

        return $user;
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->image('me.png', 64, 64);
    }

    private function profile(User $user, bool $consent = true): AiWorldIdentityProfile
    {
        $svc = app(AiWorldPrivacyService::class);
        $profile = $svc->createProfile($user, 'Me', 'self', [$this->png()]);

        return $consent ? $svc->grantConsent($user, $profile, 'own_photo', '127.0.0.1') : $profile;
    }

    public function test_photos_go_to_private_storage_and_need_consent_before_use(): void
    {
        $user = $this->user();
        $profile = $this->profile($user, false);

        $this->assertSame('pending_consent', $profile->status);
        Storage::disk('local')->assertExists($profile->reference_files[0]['path']);

        try {
            app(AiWorldProjectService::class)->create($user, $this->country->id, ['identity_profile_id' => $profile->id, 'scenes' => [['prompt' => 'x', 'seconds' => 8]]]);
            $this->fail('consent');
        } catch (AiWorldException $e) {
            $this->assertSame('profile_invalid', $e->reason);
        }

        $svc = app(AiWorldPrivacyService::class);

        try {
            $svc->grantConsent($user, $profile, 'has_permission', null);
            $this->fail('attestation');
        } catch (AiWorldException $e) {
            $this->assertSame('attestation_invalid', $e->reason);
        }

        $active = $svc->grantConsent($user, $profile, 'own_photo', '10.0.0.1');
        $this->assertSame('active', $active->status);
        $this->assertDatabaseHas('ai_world_consents', ['profile_id' => $profile->id, 'text_version' => '1']);
        $this->assertNotSame('10.0.0.1', \Modules\AI\Models\World\AiWorldConsent::first()->ip_hash);
    }

    public function test_non_image_and_too_many_photos_are_refused_and_nothing_is_kept(): void
    {
        $user = $this->user();
        $svc = app(AiWorldPrivacyService::class);

        foreach ([[UploadedFile::fake()->createWithContent('x.png', 'not an image at all')], array_fill(0, 4, $this->png())] as $photos) {
            try {
                $svc->createProfile($user, 'Me', 'self', $photos);
                $this->fail('photos');
            } catch (AiWorldException $e) {
                $this->assertSame('photos_invalid', $e->reason);
            }
        }

        $this->assertSame(0, AiWorldIdentityProfile::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_deleting_a_profile_removes_files_and_cancels_unfinished_projects_with_a_refund(): void
    {
        Queue::fake();
        $user = $this->user();
        $profile = $this->profile($user);
        $path = $profile->reference_files[0]['path'];
        $projects = app(AiWorldProjectService::class);
        $project = $projects->confirm($projects->create($user, $this->country->id, ['identity_profile_id' => $profile->id, 'scenes' => [['prompt' => 'x', 'seconds' => 8]]]));
        $this->assertSame('generating', $project->status);

        app(AiWorldPrivacyService::class)->deleteProfile($user, $profile);

        Storage::disk('local')->assertMissing($path);
        $this->assertSame('deleted', $profile->refresh()->status);
        $this->assertNull($profile->reference_files);
        $this->assertSame('cancelled', $project->refresh()->status);
        $this->assertSame(800, $project->refunded_minor);
        $this->assertNotNull(\Modules\AI\Models\World\AiWorldConsent::first()->revoked_at);
    }

    public function test_project_delete_and_report_and_retention_purge(): void
    {
        $user = $this->user();
        $svc = app(AiWorldPrivacyService::class);
        $project = app(AiWorldProjectService::class)->create($user, $this->country->id, ['scenes' => [['prompt' => 'x', 'seconds' => 8]]]);
        Storage::disk('local')->put('ai-world/'.$project->id.'/final.mp4', 'v');
        $project->forceFill(['status' => AiWorldProject::COMPLETED, 'updated_at' => now()->subDays(60)])->saveQuietly();

        $report = $svc->report($user, $project, 'impersonation', 'looks like someone else');
        $this->assertSame('open', $report->status);

        $expired = $this->profile($user);
        $expired->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertSame(['projects' => 1, 'profiles' => 1], $svc->purgeExpired());
        Storage::disk('local')->assertMissing('ai-world/'.$project->id.'/final.mp4');
        $this->assertSoftDeleted('ai_world_projects', ['id' => $project->id]);
        $this->assertSame('deleted', $expired->refresh()->status);
        $this->assertSame(1, AiWorldReport::count());
    }

    public function test_a_generating_project_cannot_be_deleted(): void
    {
        $user = $this->user();
        $project = app(AiWorldProjectService::class)->create($user, $this->country->id, ['scenes' => [['prompt' => 'x', 'seconds' => 8]]]);
        $project->forceFill(['status' => AiWorldProject::GENERATING])->save();

        $this->expectException(AiWorldException::class);
        app(AiWorldPrivacyService::class)->deleteProject($user, $project);
    }

    public function test_api_upload_consent_hide_files_and_isolate_owners(): void
    {
        $this->user();
        $res = $this->postJson('/api/user/v1/ai-world/identity-profiles', ['label' => 'Me', 'subject_type' => 'self', 'photos' => [$this->png()]])->assertStatus(201);
        $id = $res->json('data.id');
        $this->assertSame(1, $res->json('data.photos'));
        $this->assertArrayNotHasKey('reference_files', $res->json('data'));

        $this->postJson("/api/user/v1/ai-world/identity-profiles/$id/consent", ['attestation' => 'own_photo'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson('/api/user/v1/ai-world/consent-text')->assertOk()->assertJsonPath('data.version', '1');

        $this->user();
        $this->deleteJson("/api/user/v1/ai-world/identity-profiles/$id")->assertStatus(404);
    }
}
