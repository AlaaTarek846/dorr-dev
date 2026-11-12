<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMessage;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\FreelanceChatService;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Verification\FreelancerGate;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-708: warnings, limits and suspensions are issued by staff with a reason, stop only what they say (never money or agreed work),
 * and are appealed once to ANOTHER member of staff. FL-707: the quality score and risk level, with the reasons, for staff only.
 */
class FreelanceSanctionsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Freelancer $freelancer;

    private FreelanceConversation $room;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'chat'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        $this->client = User::query()->create(['name' => 'Mona Ali', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->freelancer = Freelancer::query()->create(['name' => 'Sara Hassan', 'phone' => '+966500000077', 'country_id' => $saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->room = app(FreelanceChatService::class)->open($this->client, $this->freelancer, 'project', 42);
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client, [], 'user_api');
    }

    private function asFreelancer(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->freelancer, [], 'freelance_api');
    }

    private function asStaff(string ...$permissions): Admin
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff '.uniqid(), 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $p) {
            \Spatie\Permission\Models\Permission::findOrCreate($p, 'admin_api');
            $admin->givePermissionTo($p);
        }
        $this->actAs($admin);

        return $admin;
    }

    private function actAs(Admin $admin): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    private function issue(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/v1/freelance-sanctions', $body + ['side' => 'freelancer', 'person_id' => $this->freelancer->id, 'reason' => 'Insulting the client in chat']);
    }

    private function send(string $as): \Illuminate\Testing\TestResponse
    {
        $as === 'client' ? $this->asClient() : $this->asFreelancer();
        $prefix = $as === 'client' ? '/api/mobile/v1/freelance' : '/api/freelance/v1';

        return $this->postJson("{$prefix}/chat/conversations/{$this->room->uuid}/messages", ['body' => 'Hello there', 'client_id' => uniqid()]);
    }

    public function test_a_chat_limit_stops_sending_until_another_staff_member_lifts_it_on_appeal(): void
    {
        $issuer = $this->asStaff('freelance-sanctions.view', 'freelance-sanctions.issue', 'freelance-sanctions.appeal');
        $this->issue(['type' => 'chat_limit'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_sanction_days');
        $uuid = $this->issue(['type' => 'chat_limit', 'days' => 3, 'source' => 'message_report'])->assertCreated()
            ->assertJsonPath('data.state', 'active')->assertJsonPath('data.blocks', ['chat'])->json('data.uuid');

        $this->send('freelancer')->assertForbidden()->assertJsonPath('error_code', 'freelance_restricted_chat');
        $this->send('client')->assertSuccessful();
        $this->assertSame(1, $this->freelancer->notifications()->where('data->event', 'freelance.sanction.issued')->count());

        $this->asFreelancer();
        $this->getJson('/api/freelance/v1/sanctions')->assertOk()->assertJsonPath('data.restrictions.chat.uuid', $uuid)->assertJsonPath('data.restrictions.work', null)
            ->assertJsonPath('data.items.0.can_appeal', true)->assertJsonPath('data.items.0.reason', 'Insulting the client in chat');
        $this->postJson("/api/freelance/v1/sanctions/{$uuid}/appeal", ['note' => 'ab'])->assertStatus(422);
        $this->postJson("/api/freelance/v1/sanctions/{$uuid}/appeal", ['note' => 'I was quoting their own words'])->assertCreated()
            ->assertJsonPath('data.items.0.appeal.status', 'open')->assertJsonPath('data.items.0.can_appeal', false);
        $this->postJson("/api/freelance/v1/sanctions/{$uuid}/appeal", ['note' => 'Once more please'])->assertStatus(409);
        // Someone else's sanction is not mine to appeal.
        $this->asClient();
        $this->postJson("/api/mobile/v1/freelance/sanctions/{$uuid}/appeal", ['note' => 'Not mine at all'])->assertNotFound();

        $this->actAs($issuer);
        $this->getJson('/api/admin/v1/freelance-sanctions?appeal=open')->assertOk()->assertJsonPath('pagination.counts.appeals', 1)->assertJsonPath('data.0.person', 'Sara Hassan');
        $this->postJson("/api/admin/v1/freelance-sanctions/{$uuid}/appeal", ['decision' => 'lifted'])->assertForbidden()->assertJsonPath('error_code', 'freelance_appeal_same_admin');
        $this->asStaff('freelance-sanctions.view', 'freelance-sanctions.appeal');
        $this->postJson("/api/admin/v1/freelance-sanctions/{$uuid}/appeal", ['decision' => 'lifted', 'note' => 'Fair point'])->assertOk()->assertJsonPath('data.state', 'lifted');
        $this->postJson("/api/admin/v1/freelance-sanctions/{$uuid}/appeal", ['decision' => 'upheld'])->assertStatus(409);

        $this->send('freelancer')->assertSuccessful();
        $this->assertSame(1, $this->freelancer->notifications()->where('data->event', 'freelance.sanction.appeal_decided')->count());
    }

    public function test_a_work_limit_stops_new_work_only_and_a_warning_limits_nothing(): void
    {
        $this->asStaff('freelance-sanctions.view', 'freelance-sanctions.issue');
        $this->issue(['type' => 'warning'])->assertCreated()->assertJsonPath('data.blocks', []);
        $this->send('freelancer')->assertSuccessful();

        $this->asStaff('freelance-sanctions.view', 'freelance-sanctions.issue');
        $this->issue(['type' => 'work_limit', 'days' => 7])->assertCreated();
        $this->postJson('/api/admin/v1/freelance-sanctions', ['side' => 'client', 'person_id' => $this->client->id, 'type' => 'work_limit', 'days' => 2, 'reason' => 'Off-platform payment asked'])->assertCreated();

        try {
            app(FreelancerGate::class)->assertCanOffer($this->freelancer->refresh());
            $this->fail('a work limit stops new offers');
        } catch (FreelanceException $e) {
            $this->assertSame('restricted_work', $e->errorCode);
        }
        try {
            app(ClientProjects::class)->submit(new FreelanceProject, $this->client);
            $this->fail('a work limit stops new projects');
        } catch (FreelanceException $e) {
            $this->assertSame('restricted_work', $e->errorCode);
        }
        // Chat goes on: agreed work needs it.
        $this->send('freelancer')->assertSuccessful();
        $this->send('client')->assertSuccessful();
    }

    public function test_a_suspension_needs_its_own_permission_and_a_reduced_appeal_shortens_a_limit(): void
    {
        $this->asStaff('freelance-sanctions.view', 'freelance-sanctions.issue');
        $this->issue(['type' => 'suspension'])->assertForbidden()->assertJsonPath('error_code', 'freelance_sanction_suspend_permission');
        $this->asStaff('freelance-sanctions.view', 'freelance-sanctions.issue', 'freelance-sanctions.suspend');
        $suspension = $this->issue(['type' => 'suspension'])->assertCreated()->assertJsonPath('data.ends_at', null)->json('data.uuid');
        $this->send('freelancer')->assertForbidden();

        $this->asStaff('freelance-sanctions.view', 'freelance-sanctions.issue');
        $this->postJson("/api/admin/v1/freelance-sanctions/{$suspension}/lift")->assertForbidden();
        $lifter = $this->asStaff('freelance-sanctions.view', 'freelance-sanctions.issue', 'freelance-sanctions.suspend');
        $this->postJson("/api/admin/v1/freelance-sanctions/{$suspension}/lift", ['note' => 'Talked to them'])->assertOk()->assertJsonPath('data.state', 'lifted');
        $this->postJson("/api/admin/v1/freelance-sanctions/{$suspension}/lift")->assertStatus(409);
        $this->send('freelancer')->assertSuccessful();

        $this->actAs($lifter);
        $limit = $this->issue(['type' => 'chat_limit', 'days' => 10])->assertCreated()->json('data.uuid');
        $this->asFreelancer();
        $this->postJson("/api/freelance/v1/sanctions/{$limit}/appeal", ['note' => 'Ten days is too long'])->assertCreated();
        $this->asStaff('freelance-sanctions.view', 'freelance-sanctions.appeal');
        $this->postJson("/api/admin/v1/freelance-sanctions/{$limit}/appeal", ['decision' => 'reduced'])->assertStatus(422);
        $this->postJson("/api/admin/v1/freelance-sanctions/{$limit}/appeal", ['decision' => 'reduced', 'ends_at' => now()->addDays(20)->toDateString()])->assertStatus(422)
            ->assertJsonPath('error_code', 'freelance_appeal_reduce_date');
        $this->postJson("/api/admin/v1/freelance-sanctions/{$limit}/appeal", ['decision' => 'reduced', 'ends_at' => now()->addDays(2)->toDateString()])->assertOk()
            ->assertJsonPath('data.state', 'active')->assertJsonPath('data.appeal.status', 'reduced');

        $this->travel(3)->days();
        $this->send('freelancer')->assertSuccessful();
    }

    public function test_the_appeal_days_end_and_a_viewer_cannot_issue(): void
    {
        $this->asStaff('freelance-sanctions.view');
        $this->issue(['type' => 'warning'])->assertForbidden();
        $this->asStaff('freelance-sanctions.view', 'freelance-sanctions.issue');
        $uuid = $this->issue(['type' => 'warning'])->assertCreated()->json('data.uuid');

        $this->travel(15)->days();
        $this->asFreelancer();
        $this->getJson('/api/freelance/v1/sanctions')->assertJsonPath('data.items.0.can_appeal', false);
        $this->postJson("/api/freelance/v1/sanctions/{$uuid}/appeal", ['note' => 'Too late, I know'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_appeal_closed');
    }

    /** FL-707: the reasons that raised the level are shown; an appeal that lifted a sanction stops counting it. */
    public function test_the_risk_level_follows_clear_rules_and_shows_why(): void
    {
        $viewer = $this->asStaff('freelance-sanctions.view', 'freelancers.view');
        $this->getJson("/api/admin/v1/freelance-sanctions/person/freelancer/{$this->freelancer->id}")->assertOk()
            ->assertJsonPath('data.quality.level', 'low')->assertJsonPath('data.quality.score', null)->assertJsonPath('data.quality.reasons', []);

        // One message of hers reported and upheld: medium.
        $m = FreelanceMessage::query()->create(['conversation_id' => $this->room->id, 'sender_type' => 'freelancer', 'sender_id' => $this->freelancer->id, 'kind' => 'text', 'body' => 'rude', 'created_at' => now()]);
        DB::table('freelance_message_reports')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'message_id' => $m->id, 'conversation_id' => $this->room->id, 'reporter_type' => 'client', 'reporter_id' => $this->client->id,
            'reason' => 'abuse', 'status' => 'upheld', 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson("/api/admin/v1/freelance-sanctions/person/freelancer/{$this->freelancer->id}")->assertJsonPath('data.quality.level', 'medium')
            ->assertJsonPath('data.quality.reasons.0.code', 'reports_upheld');

        // A suspension: high, and the freelancers list filters by it.
        $this->asStaff('freelance-sanctions.view', 'freelance-sanctions.issue', 'freelance-sanctions.suspend');
        $uuid = $this->issue(['type' => 'suspension', 'days' => 30])->json('data.uuid');
        $this->actAs($viewer);
        $this->getJson('/api/admin/v1/freelancers?risk=high')->assertOk()->assertJsonPath('data.0.id', $this->freelancer->id)->assertJsonPath('data.0.risk_level', 'high');
        $this->getJson('/api/admin/v1/freelancers?risk=low')->assertOk()->assertJsonCount(0, 'data');

        $this->asFreelancer();
        $this->postJson("/api/freelance/v1/sanctions/{$uuid}/appeal", ['note' => 'It was a misunderstanding'])->assertCreated();
        $this->asStaff('freelance-sanctions.appeal');
        $this->postJson("/api/admin/v1/freelance-sanctions/{$uuid}/appeal", ['decision' => 'lifted'])->assertOk();
        $this->actAs($viewer);
        $this->getJson("/api/admin/v1/freelance-sanctions/person/freelancer/{$this->freelancer->id}")->assertJsonPath('data.quality.level', 'medium')
            ->assertJsonPath('data.quality.inputs.suspensions', 0);
    }

    /** FL-707: clients who started projects are listed with their risk, and the list filters by it. */
    public function test_the_clients_list_filters_by_risk(): void
    {
        $this->asStaff('freelance-sanctions.view', 'freelance-sanctions.issue');
        $this->postJson('/api/admin/v1/freelance-sanctions', ['side' => 'client', 'person_id' => $this->client->id, 'type' => 'warning', 'reason' => 'Asked to pay outside Dorr'])->assertCreated();
        $this->getJson('/api/admin/v1/freelance-sanctions/clients')->assertOk()->assertJsonPath('data.0.name', 'Mona Ali')->assertJsonPath('pagination.counts.medium', 1);
        $this->getJson('/api/admin/v1/freelance-sanctions/clients?risk=high')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/v1/freelance-sanctions/clients?risk=medium&search=Mona')->assertOk()->assertJsonCount(1, 'data');
    }
}
