<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Jobs\RunWorldSceneJob;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\World\AiWorldPrice;
use Modules\AI\Models\World\AiWorldScene;
use Modules\AI\Models\World\AiWorldSetting;
use Modules\AI\Services\Sites\AiSiteEntitlementService;
use Modules\AI\Services\World\AiWorldPrivacyService;
use Modules\AI\Services\World\AiWorldProjectService;
use Modules\AI\Services\World\Identity\AwsSigV4Signer;
use Modules\AI\Services\World\Identity\RekognitionVerifier;
use Modules\AI\Services\World\Identity\SimilarityVerifierInterface;
use Modules\AI\Services\World\Identity\WorldFrameExtractor;
use Modules\AI\Services\World\Video\WorldVideoProvider;
use Modules\User\Models\User;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/** DORR AI WORLD Phase 6: SigV4 signing, Rekognition parsing, and the retry-or-review identity decision. */
class AiWorldIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sigv4_matches_the_published_aws_test_vector(): void
    {
        $headers = (new AwsSigV4Signer)->sign('GET', '/', '', ['host' => 'example.amazonaws.com'], '', 'us-east-1', 'service', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', '20150830T123600Z');

        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $headers['authorization'],
        );
    }

    public function test_rekognition_returns_the_best_similarity_null_for_no_face_and_hides_keys(): void
    {
        config(['ai.world.aws' => ['key' => 'AKIATESTNOTREAL', 'secret' => 'secret-not-real', 'region' => 'eu-west-1']]);
        $verifier = app(RekognitionVerifier::class);
        $this->assertTrue($verifier->isConfigured());

        Http::fakeSequence()
            ->push(['FaceMatches' => [['Similarity' => 91.5], ['Similarity' => 70]]])
            ->push(['__type' => 'InvalidParameterException', 'Message' => 'no face'], 400)
            ->push(['__type' => 'AccessDeniedException'], 403);

        $this->assertSame(91.5, $verifier->compare('a', 'b'));
        $this->assertNull($verifier->compare('a', 'b'));

        try {
            $verifier->compare('a', 'b');
            $this->fail('outage');
        } catch (\RuntimeException $e) {
            $this->assertSame('rekognition_failed', $e->getMessage());
        }

        $sent = Http::recorded()->first()[0];
        $this->assertSame('RekognitionService.CompareFaces', $sent->headers()['x-amz-target'][0] ?? json_encode($sent->headers()));
        $this->assertCount(1, $sent->headers()['Content-Type'] ?? $sent->headers()['content-type']);
        $this->assertStringContainsString('Credential=AKIATESTNOTREAL/', $sent->headers()['authorization'][0] ?? json_encode($sent->headers()));
    }

    public function test_unconfigured_verifier_is_off(): void
    {
        config(['ai.world.aws' => ['key' => null, 'secret' => null, 'region' => 'eu-west-1']]);
        $this->assertFalse(app(RekognitionVerifier::class)->isConfigured());
    }

    private function setupProject(float $similarity): array
    {
        Queue::fake();
        Storage::fake('local');
        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $country = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'status' => true, 'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => $currency->id]);
        app()->instance('resolved_country', $country);
        AiWorldPrice::create(['country_id' => $country->id, 'quality' => 'standard', 'price_per_second_minor' => 100]);
        $plan = AiPlan::query()->create(['name' => 'P', 'code' => 'p-'.uniqid(), 'usage_minutes' => 60, 'cooldown_minutes' => 0, 'duration_days' => 30, 'price' => 0, 'is_active' => true, 'video_daily_limit' => 10, 'video_max_seconds' => 8]);
        $this->mock(AiSiteEntitlementService::class, fn ($m) => $m->shouldReceive('activePlanFor')->andReturn($plan));

        app()->instance(WorldVideoProvider::class, new class implements WorldVideoProvider
        {
            public function start(string $prompt, int $seconds, array $references = []): array
            {
                return ['success' => true, 'job_id' => 'op', 'message' => '', 'provider_id' => null, 'model_key' => 'veo'];
            }

            public function poll(string $jobId): array
            {
                return ['state' => 'completed', 'message' => ''];
            }

            public function download(string $jobId, string $destinationPath): array
            {
                file_put_contents($destinationPath, 'mp4');

                return ['success' => true, 'message' => ''];
            }
        });
        app()->instance(WorldFrameExtractor::class, new class extends WorldFrameExtractor
        {
            public function frames(string $videoPath, int $seconds, int $count = 3): array
            {
                return ['f1', 'f2', 'f3'];
            }
        });
        app()->instance(SimilarityVerifierInterface::class, new class($similarity) implements SimilarityVerifierInterface
        {
            public function __construct(public float $similarity) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function compare(string $referenceBytes, string $candidateBytes): ?float
            {
                return $this->similarity;
            }
        });

        $user = User::query()->create(['name' => 'I', 'email' => 'i-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active]);
        app(WalletService::class)->firstOrCreateWallet($user, $country)->update(['spend_only_minor' => 100000, 'withdrawable_minor' => 0]);
        $privacy = app(AiWorldPrivacyService::class);
        $profile = $privacy->grantConsent($user, $privacy->createProfile($user, 'Me', 'self', [UploadedFile::fake()->image('me.png', 40, 40)]), 'own_photo', null);
        $svc = app(AiWorldProjectService::class);
        $project = $svc->confirm($svc->create($user, $country->id, ['identity_profile_id' => $profile->id, 'scenes' => [['prompt' => 'x', 'seconds' => 8]]]));

        return [$svc, $project->scenes->first()];
    }

    public function test_good_similarity_goes_to_review_with_the_score(): void
    {
        [$svc, $scene] = $this->setupProject(92.0);
        $svc->advanceScene($svc->runScene($scene));

        $scene->refresh();
        $this->assertSame(AiWorldScene::REVIEW, $scene->status);
        $this->assertSame(92.0, $scene->currentVersion->identity_score);
    }

    public function test_low_similarity_retries_then_hands_the_best_attempt_to_the_customer(): void
    {
        [$svc, $scene] = $this->setupProject(40.0);
        AiWorldSetting::current()->update(['max_retries_per_scene' => 1, 'identity_threshold' => 80]);

        $svc->advanceScene($svc->runScene($scene));
        $scene->refresh();
        $this->assertSame(AiWorldScene::QUEUED, $scene->status);
        $this->assertSame('rejected_by_identity', $scene->versions()->first()->status);
        Queue::assertPushed(RunWorldSceneJob::class, 2);

        $svc->advanceScene($svc->runScene($scene));
        $scene->refresh();
        $this->assertSame(AiWorldScene::REVIEW, $scene->status);
        $this->assertSame(40.0, $scene->currentVersion->identity_score);
    }
}
