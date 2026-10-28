<?php

namespace Modules\AI\Services\World\Identity;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/** Pulls a few evenly spaced still frames out of a clip with FFmpeg, for the identity check. */
class WorldFrameExtractor
{
    /** @return list<string> JPEG bytes */
    public function frames(string $videoPath, int $seconds, int $count = 3): array
    {
        $out = [];
        $dir = sys_get_temp_dir().'/dorr-world-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir);

        try {
            for ($i = 1; $i <= $count; $i++) {
                $at = max(0.1, $seconds * $i / ($count + 1));
                $file = "$dir/f$i.jpg";
                $run = Process::timeout(60)->run([(string) config('ai.files.video_ffmpeg_path', 'ffmpeg'), '-y', '-ss', (string) $at, '-i', $videoPath, '-frames:v', '1', '-q:v', '3', $file]);

                if ($run->successful() && is_file($file)) {
                    $out[] = (string) file_get_contents($file);
                }
            }
        } finally {
            File::deleteDirectory($dir);
        }

        return $out;
    }
}
