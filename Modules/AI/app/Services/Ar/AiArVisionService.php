<?php

namespace Modules\AI\Services\Ar;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\AI\Exceptions\AiArException;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiGateway;

/**
 * Looks at one photo of a space and lists what is in it with ROUGH sizes. These are estimates from a picture,
 * never measurements, so every answer carries the notice and a confidence, and sizes are clamped to sane values.
 * The photo is read for this request only: it is not stored and not linked to anything.
 */
class AiArVisionService
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(protected AiGateway $gateway) {}

    /** @return array{objects: list<array{name: string, width_m: ?float, height_m: ?float, confidence: float}>, estimate: true, notice: string} */
    public function analyze(UploadedFile $photo, ?string $hint = null): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($photo->getRealPath());

        if (! in_array($mime, ['image/jpeg', 'image/png'], true) || $photo->getSize() > self::MAX_BYTES) {
            throw new AiArException('capture_invalid', 422);
        }

        $provider = AiProvider::query()->where('is_enabled', true)->orderByDesc('is_default')->first();

        if ($provider === null || blank($provider->api_key)) {
            throw new AiArException('vision_unavailable', 503);
        }

        $prompt = 'List the main objects you can see in this photo of a space. Reply with ONLY a JSON array, no prose. '
            .'Each item: {"name": short English name, "width_m": rough width in metres or null, "height_m": rough height in metres or null, "confidence": 0 to 1}. '
            .'At most 12 items. Do not identify people. '.Str::limit((string) $hint, 200, '');

        try {
            $result = $this->gateway->chat($provider, [['role' => 'user', 'content' => ['text' => $prompt, 'image' => ['mime' => $mime, 'base64' => base64_encode((string) file_get_contents($photo->getRealPath())), 'file_name' => 'space']]]]);
        } catch (\Throwable) {
            throw new AiArException('vision_unavailable', 503);
        }

        $json = (string) ($result['content'] ?? '');

        if (! ($result['success'] ?? false) || preg_match('/\[.*\]/s', $json, $m) !== 1 || ! is_array($items = json_decode($m[0], true))) {
            throw new AiArException('vision_unavailable', 503);
        }

        return ['objects' => $this->clean($items), 'estimate' => true, 'notice' => __('ai.ar_measurement_notice')];
    }

    /** @return list<array{name: string, width_m: ?float, height_m: ?float, confidence: float}> */
    public function clean(array $items): array
    {
        $size = fn ($v) => is_numeric($v) && $v > 0 ? round(min((float) $v, 10), 2) : null;
        $out = [];

        foreach (array_slice($items, 0, 12) as $i) {
            if (! is_array($i) || blank($i['name'] ?? null)) {
                continue;
            }

            $out[] = [
                'name' => Str::limit(trim((string) $i['name']), 60, ''),
                'width_m' => $size($i['width_m'] ?? null),
                'height_m' => $size($i['height_m'] ?? null),
                'confidence' => round(max(0, min(1, (float) ($i['confidence'] ?? 0))), 2),
            ];
        }

        return $out;
    }
}
