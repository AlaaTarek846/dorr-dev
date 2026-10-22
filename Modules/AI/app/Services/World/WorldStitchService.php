<?php

namespace Modules\AI\Services\World;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Modules\AI\Exceptions\AiWorldException;

/** Joins approved scene clips into one video with FFmpeg, burning a small visible "DORR AI WORLD" label when the build supports it. */
class WorldStitchService
{
    /** @param list<string> $clips absolute paths in playing order */
    public function stitch(array $clips, string $destination): void
    {
        if ($clips === []) {
            throw new AiWorldException('stitch_failed', 500);
        }

        File::ensureDirectoryExists(dirname($destination));

        $list = $destination.'.txt';
        File::put($list, implode("\n", array_map(fn (string $c) => "file '".str_replace("'", "'\\''", $c)."'", $clips)));

        $ffmpeg = (string) config('ai.files.video_ffmpeg_path', 'ffmpeg');
        $input = ['-y', '-f', 'concat', '-safe', '0', '-i', $list];
        $meta = ['-metadata', 'comment=AI-generated video (DORR AI WORLD)'];
        $result = null;

        if (config('ai.world.burn_label', true)) {
            $filter = "drawtext=fontfile='".config('ai.world.label_font')."':text='DORR AI WORLD':fontcolor=white@0.85:fontsize=h/30:box=1:boxcolor=black@0.45:boxborderw=8:x=w-tw-24:y=h-th-24";
            $result = Process::timeout(900)->run([$ffmpeg, ...$input, '-vf', $filter, '-c:v', 'libx264', '-crf', '20', '-preset', 'fast', '-c:a', 'copy', ...$meta, $destination]);
        }

        if ($result === null || ! $result->successful()) {
            // No label support on this machine: deliver the video unlabelled rather than fail the whole trip.
            $result = Process::timeout(300)->run([$ffmpeg, ...$input, '-c', 'copy', ...$meta, $destination]);
        }

        File::delete($list);

        if (! $result->successful()) {
            throw new AiWorldException('stitch_failed', 500);
        }
    }
}
