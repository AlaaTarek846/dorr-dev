<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Rating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Database\Seeders\FreelanceSupportHelpSeeder;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\Freelancer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Modules\User\Models\SupportTicket;
use Tests\TestCase;

/** The freelancer app's "rate the app", FAQs and support - the DORR app's, on `freelance/v1/*`, shown on the same dashboard pages. */
class FreelancerSupportTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
    }

    private function freelancer(string $phone): Freelancer
    {
        return Freelancer::query()->create(['name' => 'Sara', 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    private function as(Freelancer $f): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f->refresh(), [], 'freelance_api');
    }

    public function test_it_needs_a_signed_in_freelancer(): void
    {
        $this->getJson('/api/freelance/v1/ratings/mine')->assertUnauthorized();
        $this->getJson('/api/freelance/v1/support-tickets')->assertUnauthorized();
        $this->getJson('/api/freelance/v1/support-help')->assertUnauthorized();
    }

    public function test_a_freelancer_rates_the_app_once_and_can_change_it(): void
    {
        $sara = $this->freelancer('+966500000001');
        $this->as($sara);

        $this->getJson('/api/freelance/v1/ratings/mine')->assertOk()->assertJsonPath('data.rated', false);
        $this->postJson('/api/freelance/v1/ratings', ['stars' => 5, 'comment' => 'Works very well'])->assertCreated();
        $this->postJson('/api/freelance/v1/ratings', ['stars' => 3.5, 'comment' => 'Works well enough'])->assertOk();
        $this->getJson('/api/freelance/v1/ratings/mine')->assertOk()->assertJsonPath('data.rated', true)->assertJsonPath('data.rating.stars', 3.5);

        $this->assertSame(1, Rating::query()->where('author_type', $sara->getMorphClass())->where('author_id', $sara->id)->count());
        $this->postJson('/api/freelance/v1/ratings', ['stars' => 9])->assertUnprocessable();
    }

    public function test_the_help_menu_is_the_freelancers_own_and_feedback_is_counted(): void
    {
        $this->seed(FreelanceSupportHelpSeeder::class);
        $this->as($this->freelancer('+966500000001'));

        $help = $this->getJson('/api/freelance/v1/support-help')->assertOk()->assertJsonStructure(['data' => ['greeting', 'items' => [['id', 'title', 'children']]]]);
        $first = $help->json('data.items.0.children.0.id');
        $this->postJson("/api/freelance/v1/support-help/{$first}/feedback", ["solved" => true])->assertOk();
        $this->assertDatabaseHas('support_help_feedback', ['support_help_node_id' => $first, 'solved' => true]);
    }

    public function test_a_freelancer_opens_a_ticket_writes_in_it_and_sees_only_their_own(): void
    {
        $sara = $this->freelancer('+966500000001');
        $lina = $this->freelancer('+966500000002');

        $this->as($sara);
        $id = $this->postJson('/api/freelance/v1/support-tickets', ['title' => 'Cannot withdraw', 'body' => 'My withdrawal is stuck.'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('support_tickets', ['id' => $id, 'freelancer_id' => $sara->id, 'user_id' => null, 'driver_id' => null]);

        $this->postJson("/api/freelance/v1/support-tickets/{$id}/messages", ['body' => 'Any news?'])->assertCreated();
        $this->assertDatabaseHas('support_messages', ['support_ticket_id' => $id, 'freelancer_id' => $sara->id, 'sender' => 'user']);
        $this->getJson('/api/freelance/v1/support-tickets')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/freelance/v1/support-tickets/{$id}/messages")->assertOk();

        $this->patchJson("/api/freelance/v1/support-tickets/{$id}/status", ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson("/api/freelance/v1/support-tickets/{$id}/messages", ['body' => 'one more'])->assertUnprocessable();

        $this->as($lina);
        $this->getJson('/api/freelance/v1/support-tickets')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/freelance/v1/support-tickets/{$id}")->assertNotFound();
        $this->assertSame('Modules.Freelance.Models.Freelancer.'.$sara->id, SupportTicket::query()->find($id)->ownerChannel());
    }

    public function test_the_dashboard_sees_a_freelancers_ticket_and_the_agents_reply_reaches_them(): void
    {
        $sara = $this->freelancer('+966500000001');
        $this->as($sara);
        $id = $this->postJson('/api/freelance/v1/support-tickets', ['title' => 'Cannot withdraw', 'body' => 'My withdrawal is stuck.'])->assertCreated()->json('data.id');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        $admin = Admin::create(['name' => 'Agent', 'email' => 'agent@x.com', 'password' => 'secret123', 'status' => 'active']);
        foreach (['view', 'reply'] as $action) {
            Permission::findOrCreate("support-tickets.$action", 'admin_api');
        }
        $admin->givePermissionTo(['support-tickets.view', 'support-tickets.reply']);
        Sanctum::actingAs($admin, [], 'admin_api');

        $this->getJson('/api/admin/v1/support-tickets')->assertOk()
            ->assertJsonPath('data.0.owner_type', 'freelancer')
            ->assertJsonPath('data.0.user.name', 'Sara')
            ->assertJsonPath('data.0.user.is_freelancer', true);
        $this->postJson("/api/admin/v1/support-tickets/{$id}/messages", ['body' => 'We are checking.'])->assertCreated()->assertJsonPath('data.sender', 'support');
        $this->assertDatabaseHas('support_messages', ['support_ticket_id' => $id, 'freelancer_id' => $sara->id, 'sender' => 'support']);

        $this->as($sara);
        $senders = array_column($this->getJson("/api/freelance/v1/support-tickets/{$id}/messages")->assertOk()->json('data'), 'sender');
        $this->assertContains('support', $senders);
    }

    public function test_the_faqs_are_open_to_a_freelancer(): void
    {
        $this->as($this->freelancer('+966500000001'));

        $this->getJson('/api/freelance/v1/faqs')->assertOk();
    }

    public function test_a_freelancer_gets_a_stable_invite_code(): void
    {
        $this->getJson('/api/freelance/v1/referrals/my-code')->assertUnauthorized();

        $sara = $this->freelancer('+966500000001');
        $this->as($sara);

        $code = $this->getJson('/api/freelance/v1/referrals/my-code')->assertOk()->assertJsonPath('data.is_active', true)->json('data.code');
        $this->assertStringStartsWith('DORRFC-', $code);
        $this->assertDatabaseHas('referral_codes', ['code' => $code, 'referrable_type' => 'freelancer', 'referrable_id' => $sara->id]);

        $this->getJson('/api/freelance/v1/referrals/my-code')->assertOk()->assertJsonPath('data.code', $code);
    }
}
