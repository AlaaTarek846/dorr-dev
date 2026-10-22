<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Exceptions\AiWorldException;
use Modules\AI\Jobs\RunWorldSceneJob;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\World\AiWorldCostLedger;
use Modules\AI\Models\World\AiWorldPrice;
use Modules\AI\Models\World\AiWorldProject;
use Modules\AI\Models\World\AiWorldScene;
use Modules\AI\Services\Sites\AiSiteEntitlementService;
use Modules\AI\Services\World\AiWorldProjectService;
use Modules\AI\Services\World\Video\WorldVideoProvider;
use Modules\AI\Services\World\WorldStitchService;
use Modules\User\Models\User;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/** DORR AI WORLD Phase 2: pricing, charge up front, scene lifecycle, retries, refunds, stitching. */
class AiWorldProjectTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    private object $video;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'eg']);
        $this->country = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true,
        ]);
        app()->instance('resolved_country', $this->country);
        AiWorldPrice::create(['country_id' => $this->country->id, 'quality' => 'standard', 'price_per_second_minor' => 100]);

        $plan = AiPlan::query()->create([
            'name' => 'P', 'code' => 'p-'.uniqid(), 'usage_minutes' => 60, 'cooldown_minutes' => 0, 'duration_days' => 30,
            'price' => 0, 'is_active' => true, 'video_daily_limit' => 10, 'video_max_seconds' => 8,
        ]);
        $this->mock(AiSiteEntitlementService::class, fn ($m) => $m->shouldReceive('activePlanFor')->andReturn($plan));

        $this->video = new class implements WorldVideoProvider
        {
            public bool $startFails = false;

            public int $starts = 0;

            public function start(string $prompt, int $seconds, array $references = []): array
            {
                $this->starts++;

                return $this->startFails
                    ? ['success' => false, 'job_id' => null, 'message' => 'boom', 'provider_id' => null, 'model_key' => null]
                    : ['success' => true, 'job_id' => 'op-'.$this->starts, 'message' => '', 'provider_id' => null, 'model_key' => 'veo'];
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
        };
        app()->instance(WorldVideoProvider::class, $this->video);
        app()->instance(WorldStitchService::class, new class extends WorldStitchService
        {
            public function stitch(array $clips, string $destination): void
            {
                @mkdir(dirname($destination), 0777, true);
                file_put_contents($destination, 'final:'.count($clips));
            }
        });
    }

    private function user(int $balanceMinor = 100000): User
    {
        $user = User::query()->create([
            'name' => 'W', 'email' => 'w-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($user, ['*'], 'user_api');
        $wallet = app(WalletService::class)->firstOrCreateWallet($user, $this->country);
        $wallet->update(['spend_only_minor' => $balanceMinor, 'withdrawable_minor' => 0]);

        return $user;
    }

    private function balance(User $user): int
    {
        $wallet = Wallet::query()->where('owner_id', $user->id)->first();

        return (int) $wallet->spend_only_minor + (int) $wallet->withdrawable_minor;
    }

    private function make(User $user, array $seconds = [8, 4]): AiWorldProject
    {
        return app(AiWorldProjectService::class)->create($user, $this->country->id, [
            'scenes' => array_map(fn ($s) => ['prompt' => 'Sunrise over the pyramids', 'seconds' => $s], $seconds),
        ]);
    }

    public function test_create_prices_the_trip_per_second(): void
    {
        $project = $this->make($this->user());

        $this->assertSame(1200, $project->estimated_minor);
        $this->assertSame(12, $project->total_seconds);
        $this->assertSame(AiWorldProject::AWAITING_CONFIRMATION, $project->status);
        $this->assertSame([800, 400], $project->scenes->pluck('price_minor')->all());
    }

    public function test_invalid_duration_and_missing_price_are_refused(): void
    {
        $user = $this->user();

        try {
            $this->make($user, [5]);
            $this->fail('duration');
        } catch (AiWorldException $e) {
            $this->assertSame('duration_invalid', $e->reason);
        }

        AiWorldPrice::query()->delete();

        try {
            $this->make($user, [8]);
            $this->fail('price');
        } catch (AiWorldException $e) {
            $this->assertSame('price_missing', $e->reason);
        }
    }

    public function test_confirm_charges_up_front_and_queues_every_scene(): void
    {
        Queue::fake();
        $user = $this->user(5000);
        $project = app(AiWorldProjectService::class)->confirm($this->make($user));

        $this->assertSame(AiWorldProject::GENERATING, $project->status);
        $this->assertSame(5000 - 1200, $this->balance($user));
        $this->assertSame(1200, $project->charged_minor);
        Queue::assertPushed(RunWorldSceneJob::class, 2);
        $this->assertSame(1, AiWorldCostLedger::query()->where('type', 'charge')->count());
    }

    public function test_confirm_refuses_a_scene_longer_than_the_plan_allows_and_charges_nothing(): void
    {
        Queue::fake();
        $short = AiPlan::query()->create([
            'name' => 'Short', 'code' => 's-'.uniqid(), 'usage_minutes' => 60, 'cooldown_minutes' => 0, 'duration_days' => 30,
            'price' => 0, 'is_active' => true, 'video_daily_limit' => 10, 'video_max_seconds' => 4,
        ]);
        $this->mock(AiSiteEntitlementService::class, fn ($m) => $m->shouldReceive('activePlanFor')->andReturn($short));
        $user = $this->user(5000);
        $project = $this->make($user);
        $this->assertGreaterThan(4, (int) $project->scenes()->max('seconds'));

        try {
            app(AiWorldProjectService::class)->confirm($project);
            $this->fail('allowed');
        } catch (\Modules\AI\Exceptions\AiWorldException $e) {
            $this->assertSame('duration_exceeds_plan', $e->reason);
        }

        $this->assertSame(5000, $this->balance($user));
        Queue::assertNothingPushed();
    }

    public function test_insufficient_balance_charges_nothing_and_queues_nothing(): void
    {
        Queue::fake();
        $user = $this->user(500);

        try {
            app(AiWorldProjectService::class)->confirm($this->make($user));
            $this->fail('balance');
        } catch (AiWorldException $e) {
            $this->assertSame('insufficient_balance', $e->reason);
        }

        $this->assertSame(500, $this->balance($user));
        Queue::assertNothingPushed();
    }

    public function test_scenes_generate_then_approving_all_stitches_the_final_video(): void
    {
        Queue::fake();
        $service = app(AiWorldProjectService::class);
        $project = $service->confirm($this->make($this->user()));

        foreach ($project->scenes as $scene) {
            $version = $service->runScene($scene);
            $this->assertFalse($service->advanceScene($version));
        }

        $project->refresh();
        $this->assertSame(AiWorldProject::REVIEW, $project->status);

        foreach ($project->scenes()->get() as $scene) {
            $service->approveScene($scene);
        }

        $project->refresh();
        $this->assertSame(AiWorldProject::COMPLETED, $project->status);
        Storage::disk('local')->assertExists($project->final_video_path);
        $this->assertSame('final:2', Storage::disk('local')->get($project->final_video_path));
    }

    public function test_a_scene_that_keeps_failing_is_retried_then_refunded(): void
    {
        Queue::fake();
        $this->video->startFails = true;
        $user = $this->user(5000);
        $service = app(AiWorldProjectService::class);
        $project = $service->confirm($this->make($user, [8]));
        $scene = $project->scenes->first();

        // default max_retries_per_scene = 2 -> 3 attempts in total
        for ($i = 0; $i < 3; $i++) {
            $service->runScene($scene->refresh());
        }

        $scene->refresh();
        $this->assertSame(AiWorldScene::FAILED, $scene->status);
        $this->assertSame(3, $this->video->starts);
        $this->assertSame(5000, $this->balance($user));
        $this->assertSame(800, $scene->refunded_minor);
        $this->assertSame(AiWorldProject::FAILED, $project->refresh()->status);
    }

    public function test_cancel_refunds_only_scenes_that_never_started(): void
    {
        Queue::fake();
        $user = $this->user(5000);
        $service = app(AiWorldProjectService::class);
        $project = $service->confirm($this->make($user));
        $service->runScene($project->scenes->first());

        $service->cancel($project->refresh());

        $this->assertSame(AiWorldProject::CANCELLED, $project->refresh()->status);
        $this->assertSame(5000 - 800, $this->balance($user));
    }

    public function test_regenerate_charges_the_scene_again(): void
    {
        Queue::fake();
        $user = $this->user(5000);
        $service = app(AiWorldProjectService::class);
        $project = $service->confirm($this->make($user, [8]));
        $version = $service->runScene($project->scenes->first());
        $service->advanceScene($version);

        $service->regenerateScene($project->scenes()->first());

        $this->assertSame(5000 - 800 - 800, $this->balance($user));
        Queue::assertPushed(RunWorldSceneJob::class, 2);
    }

    public function test_api_creates_and_hides_other_users_projects(): void
    {
        $owner = $this->user();
        $response = $this->postJson('/api/user/v1/ai-world/projects', ['scenes' => [['prompt' => 'Nile at dusk', 'seconds' => 8]]], ['Accept-Language' => 'en']);
        $response->assertStatus(201);
        $id = $response->json('data.id');

        $this->getJson('/api/user/v1/ai-world/projects/'.$id)->assertOk();

        $this->user();
        $this->getJson('/api/user/v1/ai-world/projects/'.$id)->assertStatus(404);
        $this->assertNotNull($owner);
    }
}
