<?php

namespace Modules\AI\Services\World\Identity;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** AWS Rekognition CompareFaces. Keys are server-side only; only the HTTP status is ever logged. */
class RekognitionVerifier implements SimilarityVerifierInterface
{
    public function __construct(protected AwsSigV4Signer $signer) {}

    public function isConfigured(): bool
    {
        return filled(config('ai.world.aws.key')) && filled(config('ai.world.aws.secret')) && filled(config('ai.world.aws.region'));
    }

    public function compare(string $referenceBytes, string $candidateBytes): ?float
    {
        $region = (string) config('ai.world.aws.region');
        $host = "rekognition.$region.amazonaws.com";
        $body = json_encode([
            'SourceImage' => ['Bytes' => base64_encode($referenceBytes)],
            'TargetImage' => ['Bytes' => base64_encode($candidateBytes)],
            'SimilarityThreshold' => 0,
            'QualityFilter' => 'NONE',
        ]);

        $headers = $this->signer->sign('POST', '/', '', [
            'host' => $host,
            'content-type' => 'application/x-amz-json-1.1',
            'x-amz-target' => 'RekognitionService.CompareFaces',
        ], $body, $region, 'rekognition', (string) config('ai.world.aws.key'), (string) config('ai.world.aws.secret'), gmdate('Ymd\THis\Z'));

        // host and content-type are signed but sent by the HTTP client itself (withBody); sending them twice would break the signature.
        unset($headers['host'], $headers['content-type']);

        $response = Http::withHeaders($headers)->timeout((int) config('ai.world.aws.timeout', 20))->withBody($body, 'application/x-amz-json-1.1')->post("https://$host/");

        if ($response->successful()) {
            $matches = (array) $response->json('FaceMatches', []);

            return $matches === [] ? null : (float) max(array_map(fn ($m) => (float) ($m['Similarity'] ?? 0), $matches));
        }

        // "InvalidParameterException" is what Rekognition answers when no face is found in an image: not an outage.
        if ($response->status() === 400 && str_contains((string) $response->json('__type'), 'InvalidParameterException')) {
            return null;
        }

        Log::warning('ai.world.rekognition_failed', ['status' => $response->status()]);

        throw new \RuntimeException('rekognition_failed');
    }
}
