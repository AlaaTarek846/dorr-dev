<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Modules\AI\Services\World\WorldStitchService;
use Tests\TestCase;

/** The finished trip video carries a small visible "DORR AI WORLD" label; if this FFmpeg cannot draw it, the video still ships. */
class AiWorldStitchTest extends TestCase
{
    private function run_stitch(): string
    {
        $dir = sys_get_temp_dir().'/stitch-'.uniqid();
        (new WorldStitchService)->stitch(['/tmp/a.mp4', '/tmp/b.mp4'], $dir.'/out.mp4');
        File::deleteDirectory($dir);

        return $dir;
    }

    public function test_label_is_burned_in_with_the_configured_font(): void
    {
        config(['ai.world.burn_label' => true, 'ai.world.label_font' => 'C\\:/f/font.ttf']);
        Process::fake(['*' => Process::result('', '', 0)]);

        $this->run_stitch();

        Process::assertRanTimes(fn (PendingProcess $p, $r) => true, 1);
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', $p->command), "drawtext=fontfile='C\\:/f/font.ttf':text='DORR AI WORLD'") && in_array('libx264', $p->command, true));
    }

    public function test_falls_back_to_stream_copy_when_the_label_step_fails(): void
    {
        config(['ai.world.burn_label' => true]);
        Process::fake(['*' => Process::sequence()->push(Process::result('', 'no drawtext', 1))->push(Process::result('', '', 0))]);

        $this->run_stitch();

        Process::assertRanTimes(fn () => true, 2);
        Process::assertRan(fn (PendingProcess $p) => in_array('copy', $p->command, true) && str_contains(implode(' ', $p->command), 'AI-generated video'));
    }

    public function test_label_can_be_turned_off(): void
    {
        config(['ai.world.burn_label' => false]);
        Process::fake(['*' => Process::result('', '', 0)]);

        $this->run_stitch();

        Process::assertRanTimes(fn () => true, 1);
        Process::assertRan(fn (PendingProcess $p) => ! str_contains(implode(' ', $p->command), 'drawtext'));
    }
}
