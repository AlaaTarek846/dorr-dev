<?php

namespace Tests\Unit;

use Modules\Freelance\Services\Team\ClientTeamAbilities;
use Modules\Freelance\Services\Team\ClientTeamView;
use PHPUnit\Framework\TestCase;

/** FS-104: "what is due from you next" is told to a member only when his role can take that step. */
class ClientTeamNextActionTest extends TestCase
{
    public function test_a_member_is_only_told_about_steps_his_role_can_take(): void
    {
        $cases = [
            ['manager', 'fund_milestone', null],
            ['manager', 'review_delivery', 'review_delivery'],
            ['manager', 'review_offers', 'review_offers'],
            ['manager', 'send_agreement', null],
            ['reviewer', 'review_offers', null],
            ['collaborator', 'review_delivery', null],
            ['finance', 'fund_milestone', null],
            ['delegate', 'fund_milestone', 'fund_milestone'],
            ['delegate', 'dispute', null],
        ];
        foreach ($cases as [$role, $step, $expect]) {
            $out = ClientTeamView::mask(['next_action' => $step], true, ClientTeamAbilities::ROLES[$role]);
            $this->assertSame($expect, $out['next_action'], "$role / $step");
        }
    }
}
