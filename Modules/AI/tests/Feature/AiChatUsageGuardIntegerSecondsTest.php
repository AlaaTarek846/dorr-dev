<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiPlan;
use Modules\AI\Services\AiChatUsageGuard;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Regression guard for a real bug found 2026-09-24, immediately after
 * fixing the ai_responses cast bug: the chat header showed
 * "46:11.215200000000095" instead of "46:11". Root cause: Carbon 3's
 * diff methods (diffInSeconds, etc.) can return a float with sub-second
 * precision, and AiChatUsageGuard::evaluate() fed that straight into
 * 'remaining_seconds' with no rounding - the frontend's mm:ss formatter
 * then printed the raw floating-point remainder. Fixed by rounding to a
 * whole int at the source in AiChatUsageGuard (a defensive floor was
 * also added on the frontend, but the guarantee belongs here).
 */
class AiChatUsageGuardIntegerSecondsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Usage Guard Seconds Test User',
            'email' => 'usage-seconds-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    public function test_remaining_seconds_is_always_a_whole_integer(): void
    {
        AiPlan::query()->create([
            'name' => 'Trial Plan (seconds test)',
            'code' => 'trial-seconds-test',
            'is_trial' => true,
            'is_active' => true,
            'sort_order' => 1,
            'usage_minutes' => 60,
            'cooldown_minutes' => 5,
        ]);

        $owner = $this->makeUser();

        $result = app(AiChatUsageGuard::class)->evaluate($owner);

        $this->assertTrue($result['allowed']);
        $this->assertIsInt($result['remaining_seconds'], 'remaining_seconds must be a whole int, never a float with sub-second noise');

        // The specific bug that was reported: string-interpolating the
        // value must never contain a decimal point.
        $this->assertStringNotContainsString('.', (string) $result['remaining_seconds']);
    }
}
