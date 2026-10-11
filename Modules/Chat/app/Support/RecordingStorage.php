<?php

namespace Modules\Chat\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The call recordings bucket (spec 92) — any S3-compatible storage (`chat.recordings`). LiveKit
 * Egress uploads into it with the credentials handed over per recording; the app plays a file
 * through a link signed for a few minutes; the retention purge deletes through a signed link too.
 *
 * Signed here (AWS Signature Version 4, query-string form) in a few lines instead of pulling in the
 * AWS SDK — like LiveKitToken: https://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html
 */
class RecordingStorage
{
    public function configured(): bool
    {
        return filled(config('chat.recordings.key')) && filled(config('chat.recordings.secret')) && filled(config('chat.recordings.bucket'));
    }

    /**
     * Egress's `s3` upload block.
     *
     * @return array<string, mixed>
     */
    public function egressUpload(): array
    {
        $upload = [
            'access_key' => (string) config('chat.recordings.key'),
            'secret' => (string) config('chat.recordings.secret'),
            'region' => (string) config('chat.recordings.region'),
            'bucket' => (string) config('chat.recordings.bucket'),
        ];
        if (filled($endpoint = config('chat.recordings.endpoint'))) {
            $upload['endpoint'] = (string) $endpoint;
        }
        if (config('chat.recordings.path_style')) {
            $upload['force_path_style'] = true;
        }

        return $upload;
    }

    /** Where a recording goes: `call-recordings/{call}/{recording}.mp4`. */
    public function keyFor(string $callUuid, string $recordingUuid): string
    {
        return trim((string) config('chat.recordings.prefix', 'call-recordings'), '/').'/'.$callUuid.'/'.$recordingUuid.'.mp4';
    }

    /** A link that plays the file for a few minutes. */
    public function url(string $key): string
    {
        return $this->presign('GET', $key, (int) config('chat.recordings.link_seconds', 900));
    }

    /** Gone from the bucket (already gone counts too). */
    public function delete(string $key): bool
    {
        try {
            $response = Http::timeout(10)->delete($this->presign('DELETE', $key, 300));

            return $response->successful() || $response->status() === 404;
        } catch (Throwable $e) {
            Log::warning('[chat] Recording delete: '.$e->getMessage());

            return false;
        }
    }

    /** A request to `$key` signed in its own query string, good for `$seconds`. */
    public function presign(string $method, string $key, int $seconds): string
    {
        $region = (string) (config('chat.recordings.region') ?: 'us-east-1');
        [$scheme, $host, $prefix] = $this->endpoint($region);
        $path = $prefix.'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));

        $now = now()->utc();
        $date = $now->format('Ymd');
        $stamp = $now->format('Ymd\THis\Z');
        $scope = $date.'/'.$region.'/s3/aws4_request';

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => config('chat.recordings.key').'/'.$scope,
            'X-Amz-Date' => $stamp,
            'X-Amz-Expires' => (string) max(1, min($seconds, 604800)),
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query, SORT_STRING);
        $canonicalQuery = implode('&', array_map(fn ($name, $value) => rawurlencode($name).'='.rawurlencode($value), array_keys($query), $query));

        $canonicalRequest = implode("\n", [$method, $path, $canonicalQuery, 'host:'.$host, '', 'host', 'UNSIGNED-PAYLOAD']);
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $stamp, $scope, hash('sha256', $canonicalRequest)]);

        $key = 'AWS4'.config('chat.recordings.secret');
        foreach ([$date, $region, 's3', 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }

        return $scheme.'://'.$host.$path.'?'.$canonicalQuery.'&X-Amz-Signature='.hash_hmac('sha256', $stringToSign, $key);
    }

    /**
     * AWS's regional endpoint unless one is set; the bucket in the host name, or in the path for
     * providers that want it there.
     *
     * @return array{0: string, 1: string, 2: string} scheme, host (with its port), path before the key
     */
    private function endpoint(string $region): array
    {
        $parts = parse_url(trim((string) config('chat.recordings.endpoint')) ?: 'https://s3.'.$region.'.amazonaws.com');
        $scheme = $parts['scheme'] ?? 'https';
        $host = ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $prefix = rtrim($parts['path'] ?? '', '/');
        $bucket = (string) config('chat.recordings.bucket');

        return config('chat.recordings.path_style')
            ? [$scheme, $host, $prefix.'/'.rawurlencode($bucket)]
            : [$scheme, $bucket.'.'.$host, $prefix];
    }
}
