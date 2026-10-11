<?php

namespace Modules\Chat\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LiveKit Egress (its Twirp HTTP API) for call recordings (spec 92): record a whole room into one
 * file in our bucket — everyone's voices, and for a video call their cameras in a grid — stop it,
 * and ask how it went. Best-effort like LiveKitRooms: null when LiveKit can't be reached, and the
 * minute's sync asks again. https://docs.livekit.io/home/egress/
 */
class LiveKitEgress
{
    /** EgressStatus by number, for a server that answers with numbers instead of names. */
    private const STATUSES = ['EGRESS_STARTING', 'EGRESS_ACTIVE', 'EGRESS_ENDING', 'EGRESS_COMPLETE', 'EGRESS_FAILED', 'EGRESS_ABORTED', 'EGRESS_LIMIT_REACHED'];

    /**
     * @param  array<string, mixed>  $s3  RecordingStorage::egressUpload()
     * @return array<string, mixed>|null the egress (EgressInfo)
     */
    public function startRoomComposite(string $room, bool $audioOnly, string $filepath, array $s3): ?array
    {
        return $this->call('StartRoomCompositeEgress', [
            'room_name' => $room,
            'layout' => 'grid',
            'audio_only' => $audioOnly,
            'file_outputs' => [[
                'file_type' => 'MP4',
                'filepath' => $filepath,
                // Only the file goes into the bucket (no .json beside it).
                'disable_manifest' => true,
                's3' => $s3,
            ]],
        ]);
    }

    /** @return array<string, mixed>|null */
    public function stop(string $egressId): ?array
    {
        return $this->call('StopEgress', ['egress_id' => $egressId]);
    }

    /** @return array<string, mixed>|null */
    public function info(string $egressId): ?array
    {
        $list = $this->call('ListEgress', ['egress_id' => $egressId]);

        return $list['items'][0] ?? null;
    }

    /** @param  array<string, mixed>  $info */
    public static function id(array $info): ?string
    {
        return $info['egress_id'] ?? $info['egressId'] ?? null;
    }

    /**
     * EGRESS_STARTING … EGRESS_LIMIT_REACHED (the first one is left out of the JSON as the default).
     *
     * @param  array<string, mixed>  $info
     */
    public static function status(array $info): string
    {
        $status = $info['status'] ?? 0;

        return is_numeric($status) ? (self::STATUSES[(int) $status] ?? 'EGRESS_STARTING') : (string) $status;
    }

    /**
     * The saved file: its key in the bucket, size and length (int64 comes as a string; times are
     * in nanoseconds).
     *
     * @param  array<string, mixed>  $info
     * @return array{key: ?string, size: ?int, seconds: ?int}|null
     */
    public static function file(array $info): ?array
    {
        $file = ($info['file_results'] ?? $info['fileResults'] ?? [])[0] ?? $info['file'] ?? null;
        if (! is_array($file)) {
            return null;
        }
        $started = (int) ($file['started_at'] ?? $file['startedAt'] ?? 0);
        $ended = (int) ($file['ended_at'] ?? $file['endedAt'] ?? 0);
        $duration = (int) ($file['duration'] ?? 0);
        $seconds = $ended > $started && $started > 0 ? intdiv($ended - $started, 1_000_000_000) : ($duration > 0 ? intdiv($duration, 1_000_000_000) : null);
        $size = (int) ($file['size'] ?? 0);

        return ['key' => ($file['filename'] ?? null) ?: null, 'size' => $size > 0 ? $size : null, 'seconds' => $seconds];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function call(string $method, array $body): ?array
    {
        $url = LiveKitToken::url();
        if ($url === null) {
            return null;
        }
        $base = preg_replace('#^ws(s?)://#', 'http$1://', rtrim($url, '/'));
        try {
            $response = Http::withToken(LiveKitToken::recorder())->acceptJson()->timeout(10)
                ->post($base.'/twirp/livekit.Egress/'.$method, $body);
            if (! $response->successful()) {
                Log::warning('[chat] LiveKit Egress '.$method.': '.$response->status().' '.mb_substr($response->body(), 0, 300));

                return null;
            }

            return $response->json() ?? [];
        } catch (Throwable $e) {
            Log::warning('[chat] LiveKit Egress '.$method.': '.$e->getMessage());

            return null;
        }
    }
}
