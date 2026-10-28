<?php

namespace Modules\AI\Services\World;

use Illuminate\Support\Str;
use Modules\AI\Models\AiProvider;
use Modules\AI\Models\World\AiWorldLocation;
use Modules\AI\Models\World\AiWorldSetting;
use Modules\AI\Services\AiGateway;

/**
 * The "AI Director": turns a free-text trip idea (and the chosen places) into a scene list the user can edit
 * and price BEFORE paying anything. It never charges and never starts a video. If no text model is available
 * or its answer is unusable, a plain scene-per-place plan is returned, so the screen always has something.
 */
class AiWorldDirectorService
{
    public function __construct(protected AiGateway $gateway, protected WorldPricingService $pricing) {}

    /**
     * @param  list<int>  $locationIds
     * @return array{scenes: list<array{prompt: string, seconds: int, location_id: ?int, location_name: ?string}>, total_seconds: int, estimate_minor: ?int, quality: string, source: string}
     */
    public function plan(string $brief, array $locationIds, int $countryId, string $quality = 'standard', string $language = 'en'): array
    {
        $max = AiWorldSetting::current()->max_scenes_per_project;
        $locations = AiWorldLocation::query()->whereIn('id', $locationIds)->get()->sortBy(fn ($l) => array_search($l->id, $locationIds, true))->values();
        $durations = array_values((array) config('ai.world.durations', [4, 6, 8]));

        $scenes = $this->askModel($brief, $locations->all(), $max, $durations);
        $source = 'ai';

        if ($scenes === null) {
            $scenes = $this->fallback($brief, $locations->all(), $max, $durations);
            $source = 'fallback';
        }

        $scenes = array_map(fn (array $s) => $s + ['location_name' => null], $scenes);

        foreach ($scenes as &$scene) {
            $location = $locations->firstWhere('id', $scene['location_id']);
            $scene['location_name'] = $location === null ? null : ($language === "ar" ? ($location->name_ar ?: $location->name_en) : $location->name_en);
        }
        unset($scene);

        $seconds = array_sum(array_column($scenes, 'seconds'));

        try {
            $estimate = $this->pricing->estimate($countryId, $quality, array_column($scenes, 'seconds'));
        } catch (\Throwable) {
            $estimate = null;
        }

        return ['scenes' => $scenes, 'total_seconds' => $seconds, 'estimate_minor' => $estimate, 'quality' => $quality, 'currency' => \App\Models\Country::query()->with('currency')->find($countryId)?->currency?->code, 'source' => $source];
    }

    /** @param list<AiWorldLocation> $locations @param list<int> $durations */
    protected function askModel(string $brief, array $locations, int $max, array $durations): ?array
    {
        $provider = AiProvider::query()->where('is_enabled', true)->orderByDesc('is_default')->first();

        if ($provider === null || blank($provider->api_key)) {
            return null;
        }

        $places = implode('; ', array_map(fn (AiWorldLocation $l) => $l->id.' = '.$l->name_en, $locations)) ?: 'none chosen';
        $system = 'You are a film director planning a short travel video. Reply with ONLY a JSON array, no prose. '
            ."Each item: {\"prompt\": string in English describing one cinematic shot (camera move, light, mood; no text overlays, no real people's names), "
            ."\"seconds\": one of ".implode('/', $durations).', "location_id": id from the places list or null}. '
            ."Use at most {$max} scenes. Places (id = name): {$places}.";

        try {
            $result = $this->gateway->chat($provider, [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => Str::limit($brief, 1500, '')]]);
        } catch (\Throwable) {
            return null;
        }

        if (! ($result['success'] ?? false) || blank($result['content'] ?? null)) {
            return null;
        }

        $json = $result['content'];

        if (preg_match('/\[.*\]/s', $json, $m) === 1) {
            $json = $m[0];
        }

        $items = json_decode($json, true);

        if (! is_array($items) || $items === []) {
            return null;
        }

        $known = array_map(fn (AiWorldLocation $l) => $l->id, $locations);
        $scenes = [];

        foreach (array_slice($items, 0, $max) as $item) {
            if (! is_array($item) || blank($item['prompt'] ?? null)) {
                continue;
            }

            $scenes[] = [
                'prompt' => Str::limit(trim((string) $item['prompt']), 1500, ''),
                'seconds' => $this->nearest((int) ($item['seconds'] ?? 0), $durations),
                'location_id' => in_array((int) ($item['location_id'] ?? 0), $known, true) ? (int) $item['location_id'] : null,
            ];
        }

        return $scenes === [] ? null : $scenes;
    }

    /** @param list<AiWorldLocation> $locations @param list<int> $durations */
    protected function fallback(string $brief, array $locations, int $max, array $durations): array
    {
        $length = max($durations);
        $scenes = [];

        foreach (array_slice($locations, 0, $max) as $location) {
            $scenes[] = ['prompt' => 'Cinematic establishing shot of '.$location->name_en.', golden hour, slow camera move. '.Str::limit($brief, 300, ''), 'seconds' => $length, 'location_id' => $location->id];
        }

        return $scenes !== [] ? $scenes : [['prompt' => Str::limit($brief, 1500, ''), 'seconds' => $length, 'location_id' => null]];
    }

    /** @param list<int> $durations */
    protected function nearest(int $seconds, array $durations): int
    {
        usort($durations, fn ($a, $b) => abs($a - $seconds) <=> abs($b - $seconds));

        return $durations[0];
    }
}
