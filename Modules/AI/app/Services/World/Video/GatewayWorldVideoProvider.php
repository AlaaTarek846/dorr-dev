<?php

namespace Modules\AI\Services\World\Video;

use Modules\AI\Models\AiProvider;
use Modules\AI\Models\World\AiWorldSetting;
use Modules\AI\Services\AiGateway;

/** WorldVideoProvider backed by the existing AiGateway (Veo through the Google connector). */
class GatewayWorldVideoProvider implements WorldVideoProvider
{
    public function __construct(protected AiGateway $gateway) {}

    public function start(string $prompt, int $seconds, array $references = []): array
    {
        $provider = $this->provider();

        if ($provider === null) {
            return ['success' => false, 'job_id' => null, 'message' => 'no video provider', 'provider_id' => null, 'model_key' => null];
        }

        $options = ['aspect_ratio' => (string) config('ai.video.aspect_ratio', '16:9')];

        if ($references !== []) {
            $options['reference_images'] = array_map(fn (array $r) => ['mime' => $r['mime'], 'bytes' => $r['bytes']], $references);
        }

        $result = $this->gateway->startVideo($provider, $prompt, $seconds, null, $options);

        return ['success' => $result['success'] && $result['job_id'] !== null, 'job_id' => $result['job_id'], 'message' => $result['message'], 'provider_id' => $provider->id, 'model_key' => $provider->model];
    }

    public function poll(string $jobId): array
    {
        $provider = $this->provider();
        $poll = $provider === null ? ['state' => 'failed', 'message' => 'no video provider'] : $this->gateway->pollVideo($provider, $jobId);

        return ['state' => (string) $poll['state'], 'message' => (string) $poll['message']];
    }

    public function download(string $jobId, string $destinationPath): array
    {
        $provider = $this->provider();

        return $provider === null ? ['success' => false, 'message' => 'no video provider'] : $this->gateway->downloadVideo($provider, $jobId, $destinationPath);
    }

    /** The provider chosen in WORLD settings, else the first enabled one that can really produce clips. */
    protected function provider(): ?AiProvider
    {
        $id = AiWorldSetting::current()->default_provider_id;

        if ($id !== null) {
            return AiProvider::query()->where('is_enabled', true)->find($id);
        }

        foreach (AiProvider::query()->where('is_enabled', true)->get() as $candidate) {
            if ($this->gateway->videoDurationOptions($candidate) !== []) {
                return $candidate;
            }
        }

        return null;
    }
}
