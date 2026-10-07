<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AI\Events\AiMessageBroadcast;
use Modules\AI\Jobs\AdvanceAiVideoJob;
use Modules\AI\Models\AiConversation;
use Modules\AI\Models\AiConversationAttachment;
use Modules\AI\Models\AiMediaGeneration;
use Modules\AI\Models\AiMessage;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\AiRequest;

/**
 * Chat video generation. A video takes minutes, so the chat reply only
 * confirms the job started; AdvanceAiVideoJob then calls advance() until the
 * provider is done, and the placeholder assistant message is updated in place
 * (video attached, text replaced).
 *
 * The plan decides whether and how long: ai_plans.video_daily_limit (videos a
 * day) and video_max_seconds (longest clip). The provider decides which exact
 * clip lengths exist (connector->videoDurationOptions()).
 */
class AiVideoGenerationService
{
    public const START_STARTED = 'started';

    public const START_BLOCKED = 'blocked';

    public const START_FAILED = 'failed';

    public function __construct(
        protected AiGateway $gateway,
        protected AiMediaQuotaService $quota,
    ) {}

    /** Seconds the user asked for ("اعمل فيديو 8 ثواني", "10 seconds"), or null. */
    public function requestedSeconds(string $text): ?int
    {
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        if (preg_match('/(\d{1,3})\s*(?:ثانيه|ثانية|ثواني|ثوان|ثانيت|sec(?:ond)?s?\b|s\b)/iu', $text, $m) === 1) {
            return max(1, (int) $m[1]);
        }

        return null;
    }

    /**
     * The real clip length to order: never above the plan's max, always one the
     * provider offers. Null = the plan/provider combination cannot produce any.
     *
     * @param  list<int>  $options
     */
    public function pickSeconds(array $options, ?AiPlan $plan, ?int $requested): ?int
    {
        $max = max(0, (int) ($plan?->video_max_seconds ?? 0));
        $allowed = array_values(array_filter($options, fn (int $s) => $s > 0 && $s <= $max));
        sort($allowed);

        if ($allowed === []) {
            return null;
        }

        $target = $requested ?? (int) config('ai.video.default_seconds', 4);

        foreach ($allowed as $seconds) {
            if ($seconds >= $target) {
                return $seconds;
            }
        }

        return end($allowed);
    }

    /**
     * Take a quota slot, submit the job to the provider and remember its id.
     * The caller creates the placeholder message and calls scheduleFirstPoll().
     *
     * @return array{status: string, generation: ?AiMediaGeneration, seconds: ?int, reason: ?string, limit: ?int, message: ?string}
     */
    public function start(Authenticatable $owner, AiConversation $conversation, AiRequest $aiRequest, AiProvider $callProvider, ?AiPlan $plan, string $prompt): array
    {
        $seconds = $this->pickSeconds($this->gateway->videoDurationOptions($callProvider), $plan, $this->requestedSeconds($prompt));

        if ($seconds === null) {
            return $this->blocked(AiMediaQuotaService::REASON_NOT_IN_PLAN, 0);
        }

        $reserved = $this->quota->reserve($owner, $plan, AiMediaGeneration::KIND_VIDEO, [
            'conversation_id' => $conversation->id,
            'request_id' => $aiRequest->id,
            'provider_id' => $callProvider->id,
            'model_key' => $callProvider->model,
            'prompt' => Str::limit(app(AiPiiSanitizer::class)->redactBoth($prompt), 2000, ''),
            'requested_seconds' => $seconds,
            'size' => (string) config('ai.video.size', '1280x720'),
        ]);

        if ($reserved['generation'] === null) {
            return $this->blocked((string) $reserved['decision']['reason'], (int) ($reserved['decision']['limit'] ?? 0));
        }

        /** @var AiMediaGeneration $generation */
        $generation = $reserved['generation'];
        $result = $this->gateway->startVideo($callProvider, $prompt, $seconds, $aiRequest);

        if (! $result['success'] || ! $result['job_id']) {
            $this->quota->markFailed($generation, 'provider_failed', $result['message']);

            return ['status' => self::START_FAILED, 'generation' => $generation, 'seconds' => $seconds, 'reason' => null, 'limit' => null, 'message' => $result['message']];
        }

        $generation->forceFill(['provider_job_id' => $result['job_id'], 'status' => AiMediaGeneration::STATUS_PROCESSING])->save();

        return ['status' => self::START_STARTED, 'generation' => $generation, 'seconds' => $seconds, 'reason' => null, 'limit' => null, 'message' => null];
    }

    public function scheduleFirstPoll(AiMediaGeneration $generation): void
    {
        AdvanceAiVideoJob::dispatch($generation->id)->delay(now()->addSeconds((int) config('ai.video.poll_interval', 15)));
    }

    /**
     * One polling step. Returns true while the job is still running (poll again).
     */
    public function advance(AiMediaGeneration $generation): bool
    {
        if ($generation->isFinished()) {
            return false;
        }

        $provider = $generation->provider;

        if ($provider === null || ! $generation->provider_job_id) {
            $this->fail($generation, 'provider_missing', 'provider or job id missing');

            return false;
        }

        $timeout = (int) config('ai.video.timeout', 900);

        if ($generation->started_at !== null && $generation->started_at->diffInSeconds(now(), false) > $timeout) {
            $this->fail($generation, 'timeout', 'video job exceeded '.$timeout.'s', 'ai.video_generation_timeout');

            return false;
        }

        $generation->increment('attempts');
        $poll = $this->gateway->pollVideo($provider, $generation->provider_job_id);

        if ($poll['state'] === 'failed') {
            $this->fail($generation, 'provider_failed', $poll['message']);

            return false;
        }

        if ($poll['state'] !== 'completed') {
            return true;
        }

        $this->complete($generation, $provider);

        return false;
    }

    /** @return bool true when the video was stored */
    protected function complete(AiMediaGeneration $generation, AiProvider $provider): bool
    {
        $disk = Storage::disk('public');
        $fileName = 'dorr-ai-video-'.now()->format('Ymd-His').'-'.Str::random(6).'.mp4';
        $directory = 'ai-chat/'.$generation->owner_type.'/'.$generation->owner_id.'/generated';
        $path = $directory.'/'.$fileName;
        $disk->makeDirectory($directory);

        $download = $this->gateway->downloadVideo($provider, (string) $generation->provider_job_id, $disk->path($path));

        if (! $download['success'] || ! $disk->exists($path)) {
            $this->fail($generation, 'download_failed', $download['message']);

            return false;
        }

        $message = $generation->message;

        if ($message !== null) {
            AiConversationAttachment::query()->create([
                'conversation_id' => $generation->conversation_id,
                'message_id' => $message->id,
                'file_name' => $fileName,
                'file_path' => $path,
                'mime_type' => 'video/mp4',
                'file_size' => $disk->size($path),
            ]);

            $message->forceFill(['content' => __('ai.video_generation_success'), 'is_error' => false])->save();
            $this->broadcast($generation, $message);
        }

        $this->quota->markCompleted($generation);

        return true;
    }

    protected function fail(AiMediaGeneration $generation, string $code, ?string $detail, string $messageKey = 'ai.video_generation_failed'): void
    {
        $this->quota->markFailed($generation, $code, $detail);

        $message = $generation->message;

        if ($message !== null) {
            $message->forceFill(['content' => __($messageKey), 'is_error' => true])->save();
            $this->broadcast($generation, $message);
        }
    }

    protected function broadcast(AiMediaGeneration $generation, AiMessage $message): void
    {
        if (! config('ai.chat.broadcast_enabled', false) || $generation->conversation === null) {
            return;
        }

        try {
            event(new AiMessageBroadcast($generation->conversation, $message));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array{status: string, generation: null, seconds: null, reason: string, limit: int, message: null} */
    protected function blocked(string $reason, int $limit): array
    {
        return ['status' => self::START_BLOCKED, 'generation' => null, 'seconds' => null, 'reason' => $reason, 'limit' => $limit, 'message' => null];
    }
}
