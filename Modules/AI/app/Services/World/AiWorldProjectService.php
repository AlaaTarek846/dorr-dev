<?php

namespace Modules\AI\Services\World;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AI\Exceptions\AiWorldException;
use Modules\AI\Jobs\RunWorldSceneJob;
use Modules\AI\Models\AiMediaGeneration;
use Modules\AI\Models\World\AiWorldCostLedger;
use Modules\AI\Models\World\AiWorldCountryMode;
use Modules\AI\Models\World\AiWorldIdentityProfile;
use Modules\AI\Models\World\AiWorldProject;
use Modules\AI\Models\World\AiWorldScene;
use Modules\AI\Models\World\AiWorldSceneVersion;
use Modules\AI\Models\World\AiWorldSetting;
use Modules\AI\Services\AiMediaQuotaService;
use Modules\AI\Services\AiSubscriptionBillingService;
use Modules\AI\Services\Sites\AiSiteEntitlementService;
use Modules\AI\Services\World\Video\WorldVideoProvider;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\InsufficientBalanceException;

/**
 * Orchestrates a trip video: create (priced estimate) -> confirm (charge up front) -> scenes generate
 * (retry on failure, refund what could not be produced) -> approve -> stitch. Money is kept in minor units.
 */
class AiWorldProjectService
{
    public function __construct(
        protected WorldPricingService $pricing,
        protected AiSubscriptionBillingService $billing,
        protected AiMediaQuotaService $quota,
        protected AiSiteEntitlementService $entitlements,
        protected WorldVideoProvider $video,
        protected WorldStitchService $stitcher,
        protected AiWorldAccess $access,
        protected WorldIdentityService $identity,
    ) {}

    /**
     * @param  array{title?: ?string, brief?: ?string, quality?: string, scenes: list<array{prompt: string, seconds: int, location_id?: ?int}>, references?: list<array{path: string, mime: string}>}  $data
     */
    public function create(Authenticatable $owner, int $countryId, array $data): AiWorldProject
    {
        $settings = AiWorldSetting::current();
        $scenes = array_values($data['scenes']);
        $mode = count($scenes) > 1 ? AiWorldCountryMode::MODE_MULTI : AiWorldCountryMode::MODE_SINGLE;
        $this->access->assertEnabled($mode);

        if ($scenes === [] || count($scenes) > $settings->max_scenes_per_project) {
            throw new AiWorldException('scene_count_invalid', 422);
        }

        $allowed = (array) config('ai.world.durations', [4, 6, 8]);
        $quality = (string) ($data['quality'] ?? 'standard');

        foreach ($scenes as $scene) {
            if (! in_array((int) $scene['seconds'], $allowed, true)) {
                throw new AiWorldException('duration_invalid', 422);
            }
        }

        $profileId = null;

        if (! empty($data['identity_profile_id'])) {
            $profile = AiWorldIdentityProfile::query()->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getAuthIdentifier())->find($data['identity_profile_id']);

            if ($profile === null || $profile->status !== AiWorldIdentityProfile::ACTIVE) {
                throw new AiWorldException('profile_invalid', 422);
            }

            $profileId = $profile->id;
        }

        $seconds = array_map(fn (array $s) => (int) $s['seconds'], $scenes);
        $estimate = $this->pricing->estimate($countryId, $quality, $seconds);

        return DB::transaction(function () use ($owner, $countryId, $data, $scenes, $quality, $seconds, $estimate, $mode, $profileId) {
            $project = AiWorldProject::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getAuthIdentifier(),
                'country_id' => $countryId,
                'identity_profile_id' => $profileId,
                'title' => $data['title'] ?? null,
                'brief' => $data['brief'] ?? null,
                'mode' => $mode,
                'quality' => $quality,
                'currency' => \App\Models\Country::query()->with('currency')->find($countryId)?->currency?->code,
                'status' => AiWorldProject::AWAITING_CONFIRMATION,
                'reference_files' => $data['references'] ?? null,
                'total_seconds' => array_sum($seconds),
                'estimated_minor' => $estimate,
            ]);

            foreach ($scenes as $i => $scene) {
                $project->scenes()->create([
                    'position' => $i + 1,
                    'location_id' => $scene['location_id'] ?? null,
                    'prompt' => $scene['prompt'],
                    'seconds' => (int) $scene['seconds'],
                    'price_minor' => $this->pricing->sceneAmount($countryId, $quality, (int) $scene['seconds']),
                ]);
            }

            return $project->load('scenes');
        });
    }

    /** Charges the whole estimate up front, then queues every scene. */
    public function confirm(AiWorldProject $project): AiWorldProject
    {
        if ($project->status !== AiWorldProject::AWAITING_CONFIRMATION) {
            throw new AiWorldException('invalid_state', 409);
        }

        $owner = $this->ownerOf($project);

        if ($project->identity_profile_id !== null && AiWorldIdentityProfile::query()->whereKey($project->identity_profile_id)->where('status', AiWorldIdentityProfile::ACTIVE)->doesntExist()) {
            throw new AiWorldException('profile_invalid', 422);
        }

        $this->assertVideoAllowed($owner, $project->scenes()->count(), (int) $project->scenes()->max('seconds'));

        $this->charge($project, $owner, $project->estimated_minor, null, 'confirm');

        $project->forceFill(['status' => AiWorldProject::GENERATING, 'confirmed_at' => now()])->save();

        foreach ($project->scenes as $scene) {
            $this->queueScene($scene);
        }

        return $project->refresh()->load('scenes');
    }

    public function queueScene(AiWorldScene $scene): void
    {
        $scene->forceFill(['status' => AiWorldScene::QUEUED, 'error_code' => null])->save();
        RunWorldSceneJob::dispatch($scene->id);
    }

    /** Starts one attempt with the provider. Called by RunWorldSceneJob. */
    public function runScene(AiWorldScene $scene): ?AiWorldSceneVersion
    {
        $project = $scene->project;

        if ($project->isFinal() || $scene->status !== AiWorldScene::QUEUED) {
            return null;
        }

        $owner = $this->ownerOf($project);
        $plan = $this->entitlements->activePlanFor($owner);
        $scene->increment('attempts');

        $reserved = $this->quota->reserve($owner, $plan, AiMediaGeneration::KIND_VIDEO, [
            'prompt' => Str::limit($scene->prompt, 2000, ''),
            'requested_seconds' => $scene->seconds,
        ]);

        $version = $scene->versions()->create(['number' => $scene->versions()->count() + 1, 'status' => 'queued']);

        if ($reserved['generation'] === null) {
            return $this->failAttempt($scene, $version, 'quota_'.($reserved['decision']['reason'] ?? 'blocked'), false);
        }

        $generation = $reserved['generation'];
        $result = $this->video->start($scene->prompt, $scene->seconds, $this->loadReferences($project));

        if (! $result['success']) {
            $this->quota->markFailed($generation, 'provider_failed', $result['message']);

            return $this->failAttempt($scene, $version, 'provider_failed', true);
        }

        $generation->forceFill(['provider_id' => $result['provider_id'], 'model_key' => $result['model_key'], 'provider_job_id' => $result['job_id'], 'status' => AiMediaGeneration::STATUS_PROCESSING])->save();
        $version->forceFill(['media_generation_id' => $generation->id, 'status' => 'generating'])->save();
        $scene->forceFill(['status' => AiWorldScene::GENERATING, 'current_version_id' => $version->id])->save();

        return $version;
    }

    /** One polling step. @return bool true while the provider is still working */
    public function advanceScene(AiWorldSceneVersion $version): bool
    {
        $scene = $version->scene;
        $generation = AiMediaGeneration::query()->find($version->media_generation_id);

        if ($generation === null || $generation->isFinished() || $scene->status !== AiWorldScene::GENERATING) {
            return false;
        }

        $timeout = (int) config('ai.video.timeout', 900);

        if ($generation->started_at !== null && $generation->started_at->diffInSeconds(now(), false) > $timeout) {
            $this->quota->markFailed($generation, 'timeout');
            $this->failAttempt($scene, $version, 'timeout', true);

            return false;
        }

        $poll = $this->video->poll((string) $generation->provider_job_id);

        if ($poll['state'] === 'processing') {
            return true;
        }

        if ($poll['state'] !== 'completed') {
            $this->quota->markFailed($generation, 'provider_failed', $poll['message']);
            $this->failAttempt($scene, $version, 'provider_failed', true);

            return false;
        }

        $project = $scene->project;
        $path = 'ai-world/'.$project->id.'/scene-'.$scene->position.'-v'.$version->number.'.mp4';
        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($path));
        $download = $this->video->download((string) $generation->provider_job_id, $disk->path($path));

        if (! $download['success'] || ! $disk->exists($path)) {
            $this->quota->markFailed($generation, 'download_failed', $download['message']);
            $this->failAttempt($scene, $version, 'download_failed', true);

            return false;
        }

        $this->quota->markCompleted($generation);
        $score = $this->identity->applies($project) ? $this->identity->score($project, $scene, $version->forceFill(['video_path' => $path])) : null;
        $version->forceFill(['status' => 'review', 'video_path' => $path, 'identity_score' => $score])->save();

        // Face too different from the reference photos: try again while retries remain (no extra charge),
        // otherwise hand the best attempt to the customer, who can approve it or ask for another.
        if ($score !== null && $score < (float) AiWorldSetting::current()->identity_threshold && $scene->refresh()->attempts <= AiWorldSetting::current()->max_retries_per_scene) {
            $version->forceFill(['status' => 'rejected_by_identity'])->save();
            $this->queueScene($scene);

            return false;
        }

        $scene->forceFill(['status' => AiWorldScene::REVIEW])->save();
        $this->refreshProject($project);

        return false;
    }

    public function approveScene(AiWorldScene $scene): AiWorldProject
    {
        if ($scene->status !== AiWorldScene::REVIEW) {
            throw new AiWorldException('invalid_state', 409);
        }

        $scene->forceFill(['status' => AiWorldScene::APPROVED])->save();
        $project = $scene->project;
        $this->refreshProject($project);

        return $project->refresh()->load('scenes');
    }

    /** Orders a fresh attempt of a scene the user did not like. Costs the scene price again. */
    public function regenerateScene(AiWorldScene $scene): AiWorldScene
    {
        $project = $scene->project;

        if (! in_array($scene->status, [AiWorldScene::REVIEW, AiWorldScene::FAILED], true) || $project->isFinal()) {
            throw new AiWorldException('invalid_state', 409);
        }

        $this->charge($project, $this->ownerOf($project), $scene->price_minor, $scene, 'regenerate');
        $project->forceFill(['status' => AiWorldProject::GENERATING, 'estimated_minor' => $project->estimated_minor + $scene->price_minor])->save();
        $this->queueScene($scene);

        return $scene->refresh();
    }

    public function cancel(AiWorldProject $project): AiWorldProject
    {
        if ($project->isFinal()) {
            throw new AiWorldException('invalid_state', 409);
        }

        if ($project->status === AiWorldProject::GENERATING || $project->status === AiWorldProject::REVIEW) {
            // Clips already produced are paid for; only scenes that never started are returned.
            foreach ($project->scenes()->whereIn('status', [AiWorldScene::QUEUED, AiWorldScene::DRAFT])->get() as $scene) {
                $this->refundScene($project, $scene, 'cancelled');
            }
        }

        $project->forceFill(['status' => AiWorldProject::CANCELLED])->save();

        return $project->refresh();
    }

    protected function failAttempt(AiWorldScene $scene, AiWorldSceneVersion $version, string $code, bool $retryable): AiWorldSceneVersion
    {
        $version->forceFill(['status' => 'failed', 'error_code' => $code])->save();
        $scene->refresh();

        if ($retryable && $scene->attempts <= AiWorldSetting::current()->max_retries_per_scene) {
            $this->queueScene($scene);

            return $version;
        }

        $scene->forceFill(['status' => AiWorldScene::FAILED, 'error_code' => $code])->save();
        $project = $scene->project;
        $this->refundScene($project, $scene, $code);
        $this->refreshProject($project);

        return $version;
    }

    protected function refundScene(AiWorldProject $project, AiWorldScene $scene, string $note): void
    {
        $owed = $scene->price_minor - $scene->refunded_minor;

        if ($owed <= 0) {
            return;
        }

        $owner = $this->ownerOf($project);
        $wallet = $this->billing->walletFor($owner, $project->country_id ? \App\Models\Country::query()->find($project->country_id) : null);
        $decimals = $wallet->currency?->decimal_places ?? 2;
        $this->billing->credit($wallet, $owed / (10 ** $decimals), WalletTransactionType::Refund, ['source' => 'ai_world', 'project_id' => $project->id, 'scene_id' => $scene->id]);

        $scene->forceFill(['refunded_minor' => $scene->price_minor])->save();
        $project->increment('refunded_minor', $owed);
        AiWorldCostLedger::query()->create(['project_id' => $project->id, 'scene_id' => $scene->id, 'type' => AiWorldCostLedger::REFUND, 'amount_minor' => $owed, 'currency' => $wallet->currency?->code, 'note' => $note]);
    }

    protected function charge(AiWorldProject $project, Authenticatable $owner, int $minor, ?AiWorldScene $scene, string $note): void
    {
        $country = $project->country_id ? \App\Models\Country::query()->find($project->country_id) : null;
        $wallet = $this->billing->walletFor($owner, $country);
        $decimals = $wallet->currency?->decimal_places ?? 2;

        try {
            $result = $this->billing->charge($wallet, $minor / (10 ** $decimals), WalletTransactionType::ServicePayment, ['source' => 'ai_world', 'project_id' => $project->id]);
        } catch (InsufficientBalanceException) {
            throw new AiWorldException('insufficient_balance', 402);
        }

        $project->forceFill(['charged_minor' => $project->charged_minor + $minor, 'currency' => $wallet->currency?->code, 'charge_operation_id' => $result['operation_id']])->save();
        AiWorldCostLedger::query()->create(['project_id' => $project->id, 'scene_id' => $scene?->id, 'type' => AiWorldCostLedger::CHARGE, 'amount_minor' => $minor, 'currency' => $wallet->currency?->code, 'operation_id' => $result['operation_id'], 'note' => $note]);
    }

    /** Moves the project to review / stitching / completed / failed from the state of its scenes. */
    protected function refreshProject(AiWorldProject $project): void
    {
        $statuses = $project->scenes()->pluck('status')->all();

        if (array_intersect($statuses, [AiWorldScene::QUEUED, AiWorldScene::GENERATING, AiWorldScene::DRAFT]) !== []) {
            return;
        }

        $ok = array_filter($statuses, fn ($s) => $s !== AiWorldScene::FAILED);

        if ($ok === []) {
            $project->forceFill(['status' => AiWorldProject::FAILED, 'error_code' => 'all_scenes_failed'])->save();

            return;
        }

        if (array_filter($ok, fn ($s) => $s !== AiWorldScene::APPROVED) !== []) {
            $project->forceFill(['status' => AiWorldProject::REVIEW])->save();

            return;
        }

        $project->forceFill(['status' => AiWorldProject::STITCHING])->save();
        $disk = Storage::disk('local');
        $clips = [];

        foreach ($project->scenes()->where('status', AiWorldScene::APPROVED)->get() as $scene) {
            $clips[] = $disk->path((string) $scene->currentVersion?->video_path);
        }

        $final = 'ai-world/'.$project->id.'/final.mp4';
        $this->stitcher->stitch($clips, $disk->path($final));
        $project->forceFill(['status' => AiWorldProject::COMPLETED, 'final_video_path' => $final, 'completed_at' => now()])->save();
    }

    protected function assertVideoAllowed(Authenticatable $owner, int $scenes, int $longestScene = 0): void
    {
        $plan = $this->entitlements->activePlanFor($owner);
        $decision = $this->quota->check($owner, $plan, AiMediaGeneration::KIND_VIDEO);

        if (! $decision['allowed']) {
            throw new AiWorldException('quota_'.($decision['reason'] ?? 'blocked'), 403);
        }

        if ($decision['limit'] !== null && $decision['remaining'] !== null && $decision['remaining'] < $scenes) {
            throw new AiWorldException('quota_daily_limit', 403);
        }

        if (($decision['max_seconds'] ?? 0) > 0 && $longestScene > $decision['max_seconds']) {
            throw new AiWorldException('duration_exceeds_plan', 403);
        }
    }

    /** @return list<array{mime: string, bytes: string}> */
    protected function loadReferences(AiWorldProject $project): array
    {
        $out = [];
        $files = $project->identity_profile_id !== null
            ? (AiWorldIdentityProfile::query()->where('status', AiWorldIdentityProfile::ACTIVE)->find($project->identity_profile_id)?->reference_files ?? [])
            : (array) $project->reference_files;

        foreach (array_slice((array) $files, 0, 3) as $ref) {
            if (isset($ref['path']) && Storage::disk('local')->exists($ref['path'])) {
                $out[] = ['mime' => (string) ($ref['mime'] ?? 'image/jpeg'), 'bytes' => Storage::disk('local')->get($ref['path'])];
            }
        }

        return $out;
    }

    public function ownerOf(AiWorldProject $project): Authenticatable
    {
        $class = Relation::getMorphedModel($project->owner_type) ?? $project->owner_type;

        return $class::query()->findOrFail($project->owner_id);
    }
}
