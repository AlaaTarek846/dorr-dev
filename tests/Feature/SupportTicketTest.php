<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\User\Events\SupportRealtimeEvent;
use Modules\User\Models\SupportMessage;
use Modules\User\Models\SupportSetting;
use Modules\User\Models\SupportTicket;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Support tickets: the customer opens and writes from the app, an agent answers and moves the status
 * from the dashboard, and both sides are told live (Pusher events) and by notification.
 */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);

        Country::create([
            'code' => 'SA',
            'code_alpha3' => 'SAU',
            'dial_code' => '+966',
            'phone_starts_with' => '5',
            'phone_length' => 9,
            'is_default' => true,
            'flag_id' => $flag->id,
            'currency_id' => $currency->id,
            'status' => true,
        ]);

        $this->user = $this->makeUser('+966501234567');

        // These tests are about the conversation itself; the automatic replies have their own (SupportAutoReplyTest).
        SupportSetting::current()->update(['auto_reply_enabled' => false]);
    }

    private function makeUser(string $phone): User
    {
        return User::query()->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
            'status' => UserStatus::Active,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function asUser(?User $user = null): array
    {
        // The guard remembers the user of the previous request inside one test; start every request fresh.
        $this->app['auth']->forgetGuards();

        return [
            'Authorization' => 'Bearer '.($user ?? $this->user)->createToken('mobile-app')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param  list<string>  $actions
     */
    private function adminWith(array $actions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();

        $admin = Admin::create(['name' => 'Agent '.uniqid(), 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret123', 'status' => 'active']);

        foreach ($actions as $action) {
            Permission::findOrCreate("support-tickets.$action", 'admin_api');
        }

        $admin->givePermissionTo(array_map(fn ($a) => "support-tickets.$a", $actions));
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    private function openTicket(?User $user = null, string $title = 'Payment issue', string $body = 'The wallet top-up did not arrive.'): SupportTicket
    {
        $this->postJson('/api/mobile/v1/support-tickets', ['title' => $title, 'body' => $body], $this->asUser($user))->assertCreated();

        return SupportTicket::query()->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ the customer (app)

    public function test_authenticated_user_can_open_a_support_ticket_with_a_photo(): void
    {
        Storage::fake('public');

        $this->post('/api/mobile/v1/support-tickets', [
            'title' => 'Payment issue',
            'body' => 'The wallet top-up did not arrive.',
            'image' => UploadedFile::fake()->image('shot.jpg'),
        ], $this->asUser())
            ->assertCreated()
            ->assertJsonPath('data.title', 'Payment issue')
            ->assertJsonPath('data.status', 'opened')
            ->assertJsonPath('data.accepts_replies', true)
            ->assertJsonPath('data.body', 'The wallet top-up did not arrive.');

        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertNotNull($ticket->image_path);
        Storage::disk('public')->assertExists($ticket->image_path);

        // What they wrote is the first message of the conversation, and the history starts at "opened".
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame('user', $ticket->messages()->first()->sender);
        $this->assertSame(['opened'], $ticket->activities->map(fn ($a) => $a->status->value)->all());
    }

    public function test_user_lists_only_own_tickets_and_can_filter_by_status(): void
    {
        $other = $this->makeUser('+966509876543');
        $mine = $this->openTicket($this->user, 'Mine', 'My issue');
        $this->openTicket($other, 'Theirs', 'Someone else');
        $this->openTicket($this->user, 'Second', 'Another');
        SupportTicket::query()->where('title', 'Second')->update(['status' => 'closed']);

        $this->getJson('/api/mobile/v1/support-tickets', $this->asUser())
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/mobile/v1/support-tickets?status=opened', $this->asUser())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.last_message.sender', 'user');
    }

    public function test_guest_cannot_use_support(): void
    {
        $this->postJson('/api/mobile/v1/support-tickets', ['title' => 'x', 'body' => 'y'])->assertUnauthorized();
        $this->getJson('/api/mobile/v1/support-tickets')->assertUnauthorized();
        $this->getJson('/api/mobile/v1/support-tickets/1/messages')->assertUnauthorized();
    }

    public function test_ticket_validation(): void
    {
        $this->postJson('/api/mobile/v1/support-tickets', [], $this->asUser())->assertUnprocessable()->assertJsonValidationErrors(['title', 'body']);
        $this->postJson('/api/mobile/v1/support-tickets', ['title' => 'x', 'body' => 'y', 'image' => 'nope'], $this->asUser())
            ->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_user_writes_in_their_ticket_with_text_or_a_photo(): void
    {
        Storage::fake('public');
        $ticket = $this->openTicket();

        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", ['body' => 'Any news?'], $this->asUser())
            ->assertCreated()
            ->assertJsonPath('data.sender', 'user')
            ->assertJsonPath('data.body', 'Any news?');

        $this->post("/api/mobile/v1/support-tickets/{$ticket->id}/messages", ['image' => UploadedFile::fake()->image('p.png')], $this->asUser())
            ->assertCreated();

        // A message needs words or a photo.
        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", [], $this->asUser())
            ->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->getJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", $this->asUser())
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_user_cannot_touch_someone_elses_ticket(): void
    {
        $theirs = $this->openTicket($this->makeUser('+966509876543'), 'Theirs', 'Private');

        $this->getJson("/api/mobile/v1/support-tickets/{$theirs->id}", $this->asUser())->assertNotFound();
        $this->getJson("/api/mobile/v1/support-tickets/{$theirs->id}/messages", $this->asUser())->assertNotFound();
        $this->postJson("/api/mobile/v1/support-tickets/{$theirs->id}/messages", ['body' => 'hi'], $this->asUser())->assertNotFound();
        $this->patchJson("/api/mobile/v1/support-tickets/{$theirs->id}/status", ['status' => 'closed'], $this->asUser())->assertNotFound();
    }

    public function test_user_closes_then_reopens_their_ticket_and_cannot_write_while_it_is_closed(): void
    {
        $ticket = $this->openTicket();

        $this->patchJson("/api/mobile/v1/support-tickets/{$ticket->id}/status", ['status' => 'closed'], $this->asUser())
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.accepts_replies', false);

        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", ['body' => 'hello?'], $this->asUser())
            ->assertUnprocessable()->assertJsonValidationErrors('ticket');

        $this->patchJson("/api/mobile/v1/support-tickets/{$ticket->id}/status", ['status' => 'reopened'], $this->asUser())
            ->assertOk()
            ->assertJsonPath('data.status', 'reopened')
            ->assertJsonPath('data.accepts_replies', true);

        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", ['body' => 'back again'], $this->asUser())->assertCreated();

        $this->assertSame(['opened', 'closed', 'reopened'], $ticket->activities()->orderBy('id')->get()->map(fn ($a) => $a->status->value)->all());
    }

    public function test_user_cannot_resolve_or_jump_to_a_status_that_makes_no_sense(): void
    {
        $ticket = $this->openTicket();

        // Resolving is support's call; an open ticket cannot be "reopened"; junk is rejected.
        $this->patchJson("/api/mobile/v1/support-tickets/{$ticket->id}/status", ['status' => 'resolved'], $this->asUser())->assertUnprocessable();
        $this->patchJson("/api/mobile/v1/support-tickets/{$ticket->id}/status", ['status' => 'reopened'], $this->asUser())->assertUnprocessable();
        $this->patchJson("/api/mobile/v1/support-tickets/{$ticket->id}/status", ['status' => 'banana'], $this->asUser())->assertUnprocessable();
    }

    // ------------------------------------------------------------------ the support team (dashboard)

    public function test_admin_needs_the_permission_to_see_tickets(): void
    {
        $this->openTicket();

        $this->adminWith(['reply']);
        $this->getJson('/api/admin/v1/support-tickets')->assertForbidden();

        $this->adminWith(['view']);
        $this->getJson('/api/admin/v1/support-tickets')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_lists_filters_and_searches_tickets(): void
    {
        $this->openTicket($this->user, 'Wallet problem', 'x');
        $second = $this->openTicket($this->makeUser('+966509876543'), 'Login problem', 'y');
        $second->update(['status' => 'resolved']);

        $this->adminWith(['view']);

        $this->getJson('/api/admin/v1/support-tickets')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/v1/support-tickets?status=resolved')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Login problem');
        $this->getJson('/api/admin/v1/support-tickets?search=Wallet')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.phone', '+966501234567');
        $this->getJson('/api/admin/v1/support-tickets?search=509876543')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Login problem');
        $this->getJson("/api/admin/v1/support-tickets/{$second->id}")->assertOk()->assertJsonPath('data.status', 'resolved');
    }

    public function test_agent_reply_takes_the_ticket_and_reaches_the_customer(): void
    {
        Storage::fake('public');
        $ticket = $this->openTicket();
        $agent = $this->adminWith(['view', 'reply']);

        $this->postJson("/api/admin/v1/support-tickets/{$ticket->id}/messages", ['body' => 'We are checking.'])
            ->assertCreated()
            ->assertJsonPath('data.sender', 'support')
            ->assertJsonPath('data.agent_name', $agent->name);

        $this->post("/api/admin/v1/support-tickets/{$ticket->id}/messages", ['image' => UploadedFile::fake()->image('fix.png')])->assertCreated();

        $this->assertSame($agent->id, $ticket->refresh()->admin_id);

        $messages = $this->getJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", $this->asUser())->assertOk()->json('data');
        $this->assertSame(['user', 'support', 'support'], array_column($messages, 'sender'));
        $this->assertNotNull($messages[2]['image_url']);
    }

    public function test_agent_changes_status_and_it_is_recorded(): void
    {
        $ticket = $this->openTicket();
        $agent = $this->adminWith(['view', 'change-status']);

        $this->patchJson("/api/admin/v1/support-tickets/{$ticket->id}/status", ['status' => 'resolved'])
            ->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.admin.id', $agent->id);

        $this->patchJson("/api/admin/v1/support-tickets/{$ticket->id}/status", ['status' => 'nonsense'])->assertUnprocessable();

        $this->getJson("/api/admin/v1/support-tickets/{$ticket->id}/activities")
            ->assertOk()
            ->assertJsonPath('data.1.status', 'resolved')
            ->assertJsonPath('data.1.actor', 'support');

        // The customer sees it, and can reopen a resolved ticket.
        $this->getJson("/api/mobile/v1/support-tickets/{$ticket->id}", $this->asUser())->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->patchJson("/api/mobile/v1/support-tickets/{$ticket->id}/status", ['status' => 'reopened'], $this->asUser())->assertOk();
    }

    public function test_status_change_needs_its_own_permission_and_a_closed_ticket_takes_no_agent_reply(): void
    {
        $ticket = $this->openTicket();

        $this->adminWith(['view', 'reply']);
        $this->patchJson("/api/admin/v1/support-tickets/{$ticket->id}/status", ['status' => 'closed'])->assertForbidden();

        $ticket->update(['status' => 'closed']);
        $this->postJson("/api/admin/v1/support-tickets/{$ticket->id}/messages", ['body' => 'late'])->assertUnprocessable();
    }

    // ------------------------------------------------------------------ live + notifications

    public function test_everything_that_happens_is_broadcast_live_to_both_sides(): void
    {
        Event::fake([SupportRealtimeEvent::class]);
        $agent = $this->adminWith(['view', 'reply', 'change-status']);
        $userChannel = 'Modules.User.Models.User.'.$this->user->id;
        $agentChannel = 'App.Models.Admin.'.$agent->id;

        $ticket = $this->openTicket();
        Event::assertDispatched(SupportRealtimeEvent::class, fn ($e) => $e->event === 'support.ticket.created' && in_array($agentChannel, $e->channels, true));

        Sanctum::actingAs($agent, [], 'admin_api');
        $this->postJson("/api/admin/v1/support-tickets/{$ticket->id}/messages", ['body' => 'Hi'])->assertCreated();
        Event::assertDispatched(SupportRealtimeEvent::class, fn ($e) => $e->event === 'support.message'
            && in_array($userChannel, $e->channels, true)
            && $e->payload['message']['body'] === 'Hi'
            && $e->payload['ticket']['id'] === $ticket->id);

        $this->patchJson("/api/admin/v1/support-tickets/{$ticket->id}/status", ['status' => 'resolved'])->assertOk();
        Event::assertDispatched(SupportRealtimeEvent::class, fn ($e) => $e->event === 'support.ticket.updated'
            && in_array($userChannel, $e->channels, true)
            && $e->payload['ticket']['status'] === 'resolved');

        $this->patchJson('/api/mobile/v1/support-tickets/'.$ticket->id.'/status', ['status' => 'reopened'], $this->asUser())->assertOk();
        Event::assertDispatched(SupportRealtimeEvent::class, fn ($e) => $e->event === 'support.ticket.updated'
            && in_array($agentChannel, $e->channels, true)
            && $e->payload['ticket']['status'] === 'reopened');
    }

    public function test_the_customer_gets_an_in_app_notification_when_support_answers(): void
    {
        $ticket = $this->openTicket();
        $this->adminWith(['view', 'reply', 'change-status']);

        $this->postJson("/api/admin/v1/support-tickets/{$ticket->id}/messages", ['body' => 'Hi'])->assertCreated();
        $this->patchJson("/api/admin/v1/support-tickets/{$ticket->id}/status", ['status' => 'resolved'])->assertOk();

        $titles = $this->user->notifications->pluck('data.title')->all();
        $this->assertContains('support_ticket_reply_title', $titles);
        $this->assertContains('support_ticket_status_title', $titles);
    }

    public function test_agents_are_notified_when_a_customer_opens_or_writes(): void
    {
        $agent = $this->adminWith(['view']);

        $ticket = $this->openTicket();
        $this->postJson("/api/mobile/v1/support-tickets/{$ticket->id}/messages", ['body' => 'Hello?'], $this->asUser())->assertCreated();

        $titles = $agent->notifications->pluck('data.title')->all();
        $this->assertContains('support_ticket_new_title', $titles);
        $this->assertContains('support_ticket_customer_reply_title', $titles);
        $this->assertSame(2, SupportMessage::query()->count());
    }
}
