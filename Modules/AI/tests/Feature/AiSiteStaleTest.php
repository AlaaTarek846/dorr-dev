<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Models\AiSiteVersion;
use Modules\User\Models\User;
use Tests\TestCase;

class AiSiteStaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stuck_edit_is_failed_and_the_site_goes_back_to_ready(): void
    {
        $user = User::query()->create(['name' => 'S', 'email' => 's@example.test', 'password' => bcrypt('x'), 'status' => UserStatus::Active]);
        $project = AiSiteProject::query()->create([
            'owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'slug' => str_repeat('a', 40),
            'title' => 'T', 'brief' => [], 'status' => AiSiteProject::STATUS_GENERATING, 'access_type' => AiSiteProject::ACCESS_PLAN,
        ]);
        $good = AiSiteVersion::query()->create(['project_id' => $project->id, 'number' => 1, 'kind' => 'generate', 'status' => 'completed']);
        $project->forceFill(['current_version_id' => $good->id])->save();
        $stuck = AiSiteVersion::query()->create(['project_id' => $project->id, 'number' => 2, 'kind' => 'edit', 'status' => 'processing']);
        AiSiteVersion::query()->whereKey($stuck->id)->update(['updated_at' => now()->subHour()]);

        Artisan::call('ai:fail-stale-sites');

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame(AiSiteProject::STATUS_READY, $project->fresh()->status);
    }

    public function test_a_recent_build_is_left_alone(): void
    {
        $user = User::query()->create(['name' => 'S', 'email' => 's2@example.test', 'password' => bcrypt('x'), 'status' => UserStatus::Active]);
        $project = AiSiteProject::query()->create([
            'owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'slug' => str_repeat('b', 40),
            'title' => 'T', 'brief' => [], 'status' => AiSiteProject::STATUS_GENERATING, 'access_type' => AiSiteProject::ACCESS_PLAN,
        ]);
        $v = AiSiteVersion::query()->create(['project_id' => $project->id, 'number' => 1, 'kind' => 'generate', 'status' => 'processing']);

        Artisan::call('ai:fail-stale-sites');

        $this->assertSame('processing', $v->fresh()->status);
    }
}
