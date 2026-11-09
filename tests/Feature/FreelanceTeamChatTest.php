<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Events\FreelanceTeamChatChanged;
use Modules\Freelance\Models\FreelanceDispute;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceInternalMessage;
use Modules\Freelance\Models\FreelanceInternalThread;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeams;
use Modules\Freelance\Services\FreelancerTeam\TeamChat;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FS-150d: a freelancers' team talks inside, apart from the chat with the client - a general thread and one per project the team's Owners
 * signed; only the team's active people read and write; messages are live to the team and pushed to the others; the sender takes one back
 * for a few minutes; the staff read a project's threads only while a dispute on it is being decided, with their own permission, every
 * reading audited and the Owners told once; a message taken back during such a dispute keeps its text for the staff only.
 */
class FreelanceTeamChatTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const FREELANCER = '/api/freelance/v1';

    private Country $saudi;

    private User $owner;

    private FreelanceProject $project;

    private Freelancer $sara;

    private Freelancer $lina;

    private Freelancer $noor;

    private Freelancer $huda;

    private FreelanceTeam $team;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'teams'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->owner = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->project = $this->newProject('A booking website');
        $this->sara = $this->freelancer('+966500000091', 'Sara Ali');
        $this->lina = $this->freelancer('+966500000092', 'Lina');
        $this->noor = $this->freelancer('+966500000093', 'Noor');
        $this->huda = $this->freelancer('+966500000094', 'Huda');

        $teams = app(FreelancerTeams::class);
        $this->team = $teams->create($this->sara, 'Dorr Studio');
        $teams->join($this->team, $this->lina, 'pm');
        $teams->join($this->team, $this->noor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function newProject(string $title): FreelanceProject
    {
        return FreelanceProject::query()->create([
            'client_id' => $this->owner->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => FreelanceSpecialty::query()->value('id'), 'title' => $title,
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
    }

    private function freelancer(string $phone, string $name): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    private function as(User|Freelancer|\Modules\Admin\Models\Admin $who): void
    {
        $this->app['auth']->forgetGuards();
        $guard = match (true) {
            $who instanceof Freelancer => 'freelance_api',
            $who instanceof User => 'user_api',
            default => 'admin_api',
        };
        Sanctum::actingAs($who->refresh(), [], $guard);
    }

    /** Sara (the team's Owner) chosen on the project and the agreement active; returns the first part. */
    private function active(): FreelanceMilestone
    {
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/projects/{$this->project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ])->assertCreated();
        $this->as($this->owner);
        $offer = FreelanceOffer::query()->where('freelancer_id', $this->sara->id)->firstOrFail();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$offer->uuid}/choose")->assertOk();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/agreement/milestones", ['milestones' => [['title' => 'Design', 'amount_minor' => 100000], ['title' => 'Build', 'amount_minor' => 250000]]])->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/projects/{$this->project->uuid}/agreement/approve")->assertOk();

        return FreelanceMilestone::query()->orderBy('position')->firstOrFail();
    }

    private function dispute(string $status = FreelanceDispute::OPEN): FreelanceDispute
    {
        $part = FreelanceMilestone::query()->orderBy('position')->firstOrFail();

        return FreelanceDispute::query()->create([
            'project_id' => $this->project->id, 'agreement_id' => $part->agreement_id, 'milestone_id' => $part->id, 'opened_by' => 'client', 'opener_id' => $this->owner->id,
            'reason' => 'quality', 'details' => 'The pages are not what we agreed.', 'milestone_status' => 'delivered', 'status' => $status,
        ]);
    }

    private function admin(array $permissions): \Modules\Admin\Models\Admin
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = \Modules\Admin\Models\Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'admin_api');
            $admin->givePermissionTo($name);
        }

        return $admin;
    }

    private function url(string $suffix = ''): string
    {
        return self::FREELANCER."/teams/{$this->team->uuid}/chat".$suffix;
    }

    /** The team's general thread, as Sara lists it. */
    private function general(): string
    {
        $this->as($this->sara);

        return (string) $this->getJson($this->url('/threads'))->assertOk()->json('data.threads.0.uuid');
    }

    private function say(Freelancer $who, string $thread, string $body, ?string $clientId = null): int
    {
        $this->as($who);

        return (int) $this->postJson($this->url("/threads/{$thread}/messages"), ['body' => $body, 'client_msg_id' => $clientId])->assertCreated()->json('data.message.id');
    }

    private function told(Freelancer $who, string $event): int
    {
        return $who->notifications()->where('data->event', $event)->count();
    }

    public function test_the_team_chat_is_off_until_the_admin_opens_teams_for_the_country(): void
    {
        $thread = $this->general();
        FreelanceFeatureRule::query()->where('feature', 'teams')->delete();
        FreelanceFeatures::forget();

        $this->as($this->sara);
        $this->getJson(self::FREELANCER.'/teams')->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->getJson($this->url('/threads'))->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->postJson($this->url("/threads/{$thread}/messages"), ['body' => 'Hello'])->assertForbidden();
        $this->assertSame(0, FreelanceInternalMessage::query()->count());
    }

    public function test_a_person_lists_the_teams_he_is_in_and_the_general_thread_is_made_once(): void
    {
        $this->as($this->lina);
        $this->getJson(self::FREELANCER.'/teams')->assertOk()->assertJsonCount(1, 'data.teams')->assertJsonPath('data.teams.0.uuid', $this->team->uuid)
            ->assertJsonPath('data.teams.0.name', 'Dorr Studio')->assertJsonPath('data.teams.0.role', 'pm')->assertJsonPath('data.teams.0.status', 'active');
        $this->as($this->huda);
        $this->getJson(self::FREELANCER.'/teams')->assertOk()->assertJsonCount(0, 'data.teams');

        $this->as($this->lina);
        $first = $this->getJson($this->url('/threads'))->assertOk()->assertJsonCount(1, 'data.threads')->assertJsonPath('data.threads.0.general', true)
            ->assertJsonPath('data.threads.0.project', null)->assertJsonPath('data.threads.0.unread', 0)->assertJsonPath('data.threads.0.can_write', true)
            ->assertJsonPath('data.threads.0.under_review', false)->assertJsonPath('data.can_write', true)->assertJsonPath('data.can_open_project', false)
            ->assertJsonPath('data.notice', __('freelance.internal_chat_notice'));
        $this->as($this->sara);
        $this->getJson($this->url('/threads'))->assertOk()->assertJsonCount(1, 'data.threads')->assertJsonPath('data.threads.0.uuid', $first->json('data.threads.0.uuid'))
            ->assertJsonPath('data.can_open_project', true);
        $this->assertSame(1, FreelanceInternalThread::query()->count());
    }

    public function test_only_the_teams_active_people_reach_its_chat(): void
    {
        $thread = $this->general();
        $message = $this->say($this->sara, $thread, 'Welcome to the team');
        app(FreelancerTeams::class)->leave($this->team, $this->noor);

        // A freelancer who is not in the team, and one who left it: the team looks like it does not exist.
        foreach ([$this->huda, $this->noor] as $who) {
            $this->as($who);
            $this->getJson($this->url('/threads'))->assertNotFound();
            $this->getJson($this->url("/threads/{$thread}/messages"))->assertNotFound();
            $this->postJson($this->url("/threads/{$thread}/messages"), ['body' => 'Let me in'])->assertNotFound();
            $this->postJson($this->url("/threads/{$thread}/read"))->assertNotFound();
            $this->deleteJson($this->url("/messages/{$message}"))->assertNotFound();
        }
        // The client is not a freelancer at all.
        $this->as($this->owner);
        $this->getJson($this->url('/threads'))->assertUnauthorized();
        // A thread of another team is not reachable through this one.
        $other = app(FreelancerTeams::class)->create($this->huda, 'Huda team');
        $foreign = app(TeamChat::class)->general($other);
        $this->as($this->sara);
        $this->getJson($this->url("/threads/{$foreign->uuid}/messages"))->assertNotFound();
        $this->postJson($this->url("/threads/{$foreign->uuid}/messages"), ['body' => 'Wrong door'])->assertNotFound();
        $this->assertSame(1, FreelanceInternalMessage::query()->count());

        // The live channel follows the same rule.
        $chat = app(TeamChat::class);
        $this->assertTrue($chat->listens($this->sara->refresh(), $this->team->uuid));
        $this->assertTrue($chat->listens($this->lina->refresh(), $this->team->uuid));
        $this->assertFalse($chat->listens($this->noor->refresh(), $this->team->uuid));
        $this->assertFalse($chat->listens($this->huda->refresh(), $this->team->uuid));
        $this->assertFalse($chat->listens($this->owner->refresh(), $this->team->uuid));
        $this->assertFalse($chat->listens(null, $this->team->uuid));
        $this->assertFalse($chat->listens($this->sara, (string) Str::uuid()));
    }

    public function test_a_message_reaches_the_team_live_and_by_push_and_is_counted_unread_until_read(): void
    {
        $thread = $this->general();
        Event::fake([FreelanceTeamChatChanged::class]);

        $this->as($this->lina);
        $sent = $this->postJson($this->url("/threads/{$thread}/messages"), ['body' => '  <b>Draft</b> is ready  ', 'client_msg_id' => 'm-1'])->assertCreated()
            ->assertJsonPath('data.message.body', 'Draft is ready')->assertJsonPath('data.message.kind', 'text')->assertJsonPath('data.message.mine', true)
            ->assertJsonPath('data.message.from.name', 'Lina')->assertJsonPath('data.message.client_msg_id', 'm-1');
        $id = (int) $sent->json('data.message.id');

        // Pushed to the others (with the team's name), never to the sender.
        $this->assertSame(1, $this->told($this->sara, 'freelance.internal_message'));
        $this->assertSame(1, $this->told($this->noor, 'freelance.internal_message'));
        $this->assertSame(0, $this->told($this->lina, 'freelance.internal_message'));
        $this->assertSame(0, $this->told($this->huda, 'freelance.internal_message'));
        Event::assertDispatched(FreelanceTeamChatChanged::class, fn (FreelanceTeamChatChanged $e) => $e->action === 'message' && $e->threadUuid === $thread
            && $e->teamUuid === $this->team->uuid && $e->broadcastOn()->name === 'private-freelance-team.'.$this->team->uuid && $e->broadcastWith()['message']['id'] === $id
            && $e->broadcastWith()['message']['mine'] === null);

        // The sender read what she wrote; the others have one unread.
        $this->getJson($this->url('/threads'))->assertJsonPath('data.threads.0.unread', 0)->assertJsonPath('data.threads.0.last_message_preview', 'Draft is ready');
        $this->as($this->sara);
        $this->getJson($this->url('/threads'))->assertJsonPath('data.threads.0.unread', 1);
        $this->getJson($this->url("/threads/{$thread}/messages"))->assertOk()->assertJsonCount(1, 'data.messages')->assertJsonPath('data.messages.0.mine', false)
            ->assertJsonPath('data.messages.0.body', 'Draft is ready')->assertJsonPath('data.thread.unread', 1);
        $this->postJson($this->url("/threads/{$thread}/read"))->assertOk()->assertJsonPath('data.read', 1);
        $this->getJson($this->url('/threads'))->assertJsonPath('data.threads.0.unread', 0);
        $this->postJson($this->url("/threads/{$thread}/read"))->assertOk()->assertJsonPath('data.read', 0);
        Event::assertDispatched(FreelanceTeamChatChanged::class, fn (FreelanceTeamChatChanged $e) => $e->action === 'read' && $e->readerId === $this->sara->id && $e->upTo === $id);
        Event::assertDispatchedTimes(FreelanceTeamChatChanged::class, 2);
        $this->as($this->noor);
        $this->getJson($this->url('/threads'))->assertJsonPath('data.threads.0.unread', 1);
    }

    public function test_a_message_sent_twice_is_stored_once_and_an_empty_or_long_one_is_refused(): void
    {
        $thread = $this->general();
        $first = $this->say($this->lina, $thread, 'Hello team', 'same-id');
        $again = $this->say($this->lina, $thread, 'Hello team', 'same-id');
        $this->assertSame($first, $again);
        // Another person may use the same id of his app.
        $this->assertNotSame($first, $this->say($this->noor, $thread, 'Hi', 'same-id'));
        $this->assertSame(2, FreelanceInternalMessage::query()->count());
        // Sara was told of Lina's message once (not of its second sending) and of Noor's.
        $this->assertSame(2, $this->told($this->sara, 'freelance.internal_message'));

        $this->as($this->lina);
        $this->postJson($this->url("/threads/{$thread}/messages"), ['body' => '<i></i>'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_chat_empty');
        $this->postJson($this->url("/threads/{$thread}/messages"), [])->assertUnprocessable();
        $this->postJson($this->url("/threads/{$thread}/messages"), ['body' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->assertSame(2, FreelanceInternalMessage::query()->count());
    }

    public function test_messages_page_newest_first(): void
    {
        $thread = $this->general();
        $ids = [];
        foreach (['one', 'two', 'three'] as $word) {
            $ids[] = $this->say($this->lina, $thread, $word);
        }

        $this->as($this->sara);
        $page = $this->getJson($this->url("/threads/{$thread}/messages?limit=2"))->assertOk();
        $this->assertSame(['three', 'two'], $page->json('data.messages.*.body'));
        $this->assertSame($ids[1], $page->json('data.next_before'));
        $rest = $this->getJson($this->url("/threads/{$thread}/messages?limit=2&before={$ids[1]}"))->assertOk();
        $this->assertSame(['one'], $rest->json('data.messages.*.body'));
        $this->assertNull($rest->json('data.next_before'));
        $this->getJson($this->url("/threads/{$thread}/messages?limit=500"))->assertUnprocessable();
    }

    public function test_the_sender_takes_a_message_back_within_a_few_minutes_and_its_text_is_gone(): void
    {
        $thread = $this->general();
        $mine = $this->say($this->lina, $thread, 'Oops, wrong thread');
        $hers = $this->say($this->noor, $thread, 'Hello');

        $this->as($this->lina);
        $this->deleteJson($this->url("/messages/{$hers}"))->assertNotFound();
        $this->deleteJson($this->url('/messages/999999'))->assertNotFound();
        $this->deleteJson($this->url("/messages/{$mine}"))->assertOk();
        $this->deleteJson($this->url("/messages/{$mine}"))->assertUnprocessable()->assertJsonPath('error_code', 'freelance_message_not_deletable');
        $this->getJson($this->url("/threads/{$thread}/messages"))->assertJsonPath('data.messages.1.kind', 'deleted')->assertJsonPath('data.messages.1.body', null);
        // No dispute on anything: the text is not kept at all.
        $this->assertNull(FreelanceInternalMessage::query()->findOrFail($mine)->body);
        $this->assertNotNull(FreelanceInternalMessage::query()->findOrFail($mine)->deleted_at);

        // Too late after a few minutes.
        Carbon::setTestNow(now()->addMinutes(TeamChat::DELETE_WINDOW_MINUTES + 1));
        $this->as($this->noor);
        $this->deleteJson($this->url("/messages/{$hers}"))->assertUnprocessable()->assertJsonPath('error_code', 'freelance_message_not_deletable');
        $this->assertSame('Hello', FreelanceInternalMessage::query()->findOrFail($hers)->body);
    }

    public function test_an_owner_opens_the_thread_of_a_project_the_team_signed_once(): void
    {
        $this->active();
        $unsigned = $this->newProject('Another site');

        // A PM or member does not open project threads; a project without the team's agreement has none.
        $this->as($this->lina);
        $this->postJson($this->url('/threads'), ['project' => $this->project->uuid])->assertForbidden()->assertJsonPath('error_code', 'freelance_freelancer_team_forbidden');
        $this->as($this->sara);
        $this->postJson($this->url('/threads'), ['project' => $unsigned->uuid])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_internal_chat_project_invalid');
        $this->postJson($this->url('/threads'), ['project' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_internal_chat_project_invalid');
        $this->postJson($this->url('/threads'), ['project' => 'nope'])->assertUnprocessable();

        $thread = (string) $this->postJson($this->url('/threads'), ['project' => $this->project->uuid])->assertOk()
            ->assertJsonPath('data.thread.general', false)->assertJsonPath('data.thread.project.uuid', $this->project->uuid)
            ->assertJsonPath('data.thread.project.title', 'A booking website')->assertJsonPath('data.thread.under_review', false)->json('data.thread.uuid');
        $this->postJson($this->url('/threads'), ['project' => $this->project->uuid])->assertOk()->assertJsonPath('data.thread.uuid', $thread);
        // A completed agreement keeps its thread open too.
        FreelanceAgreement::query()->update(['status' => FreelanceAgreement::COMPLETED]);
        $this->postJson($this->url('/threads'), ['project' => $this->project->uuid])->assertOk()->assertJsonPath('data.thread.uuid', $thread);

        // The Owners and PMs read and write it; the general thread comes first.
        $this->say($this->lina, $thread, 'I will take the booking page');
        $this->as($this->sara);
        $this->getJson($this->url('/threads'))->assertJsonCount(2, 'data.threads')->assertJsonPath('data.threads.0.general', true)
            ->assertJsonPath('data.threads.1.uuid', $thread)->assertJsonPath('data.threads.1.unread', 1);
        $this->assertSame(2, FreelanceInternalThread::query()->count());
        // FS-206a / D9: a member who does not work on the project neither sees its thread nor writes in it (the general one stays his).
        $this->as($this->noor);
        $this->getJson($this->url('/threads'))->assertJsonCount(1, 'data.threads')->assertJsonPath('data.threads.0.general', true);
        $this->postJson($this->url("/threads/{$thread}/messages"), ['body' => 'Hello'])->assertNotFound();
        $this->getJson($this->url("/threads/{$thread}/messages"))->assertNotFound();
        $this->assertSame(0, $this->told($this->noor, 'freelance.internal_message'), 'nor is he pushed its messages');
    }

    public function test_a_suspended_team_leaves_the_chat_to_read_only(): void
    {
        $thread = $this->general();
        $this->say($this->lina, $thread, 'Before');
        $this->team->forceFill(['status' => FreelanceTeam::SUSPENDED])->save();

        $this->as($this->lina);
        $this->getJson($this->url('/threads'))->assertOk()->assertJsonPath('data.can_write', false)->assertJsonPath('data.threads.0.can_write', false);
        $this->getJson($this->url("/threads/{$thread}/messages"))->assertOk()->assertJsonCount(1, 'data.messages');
        $this->postJson($this->url("/threads/{$thread}/messages"), ['body' => 'After'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_freelancer_team_inactive');
        $this->as($this->sara);
        $this->postJson($this->url('/threads'), ['project' => $this->project->uuid])->assertStatus(409);
        $this->assertSame(1, FreelanceInternalMessage::query()->count());
    }

    public function test_the_staff_read_a_projects_threads_only_while_its_dispute_is_decided_with_their_own_permission(): void
    {
        $this->active();
        $this->as($this->sara);
        $projectThread = (string) $this->postJson($this->url('/threads'), ['project' => $this->project->uuid])->assertOk()->json('data.thread.uuid');
        $general = $this->general();
        $this->say($this->lina, $projectThread, 'The client changed the colours twice');
        $this->say($this->sara, $general, 'Team dinner on Thursday');
        // A thread about another project of the team is not part of this dispute.
        $otherProject = $this->newProject('Another site');
        $otherThread = FreelanceInternalThread::query()->create(['team_id' => $this->team->id, 'project_id' => $otherProject->id]);
        FreelanceInternalMessage::query()->create(['thread_id' => $otherThread->id, 'sender_freelancer_id' => $this->sara->id, 'body' => 'Another project', 'created_at' => now()]);

        $dispute = $this->dispute();
        $path = "/api/admin/v1/freelance-disputes/{$dispute->uuid}/internal-chat";

        // The disputes desk alone is not enough: reading the internal chat is its own permission.
        $this->as($this->admin(['freelance-disputes.view', 'freelance-disputes.update']));
        $this->getJson($path)->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('action', 'freelance.internal_chat.viewed')->count());

        // The team sees that its project thread may be read now.
        $this->as($this->lina);
        $this->getJson($this->url('/threads'))->assertJsonPath('data.threads.1.under_review', true)->assertJsonPath('data.threads.0.under_review', false);

        $staff = $this->admin(['freelance-internal-chat.view']);
        $this->as($staff);
        $res = $this->getJson($path)->assertOk()->assertJsonPath('data.dispute', $dispute->uuid)->assertJsonPath('data.project.uuid', $this->project->uuid)
            ->assertJsonCount(1, 'data.threads')->assertJsonPath('data.threads.0.uuid', $projectThread)->assertJsonPath('data.threads.0.team.name', 'Dorr Studio')
            ->assertJsonPath('data.threads.0.messages.0.body', 'The client changed the colours twice')->assertJsonPath('data.threads.0.messages.0.from.name', 'Lina')
            ->assertJsonPath('data.threads.0.messages.0.from.role', 'pm')->assertJsonPath('data.threads.0.messages.0.deleted', false);
        $json = (string) $res->getContent();
        $this->assertStringNotContainsString('Team dinner', $json);
        $this->assertStringNotContainsString('Another project', $json);

        // Every reading is audited with who; the Owners are told the first time only.
        $this->getJson($path)->assertOk();
        $logs = AuditLog::query()->where('action', 'freelance.internal_chat.viewed')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($staff->id, (int) $logs->first()->actor_id);
        $this->assertSame($dispute->id, (int) $logs->first()->subject_id);
        $this->assertSame(1, $this->told($this->sara, 'freelance.internal_chat_reviewed'));
        $this->assertSame(0, $this->told($this->lina, 'freelance.internal_chat_reviewed'));

        // Reading only: the staff have no way to write, and the decided / resolved case closes the chat to them again; an appeal reopens it.
        foreach ([FreelanceDispute::DECIDED, FreelanceDispute::RESOLVED] as $status) {
            $dispute->forceFill(['status' => $status])->save();
            $this->getJson($path)->assertForbidden()->assertJsonPath('error_code', 'freelance_internal_chat_not_open');
        }
        $dispute->forceFill(['status' => FreelanceDispute::APPEALED])->save();
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data.threads');
        $this->assertSame(1, $this->told($this->sara, 'freelance.internal_chat_reviewed'));
        $this->postJson($path, ['body' => 'Hello'])->assertStatus(405);
    }

    public function test_a_message_taken_back_during_a_dispute_keeps_its_text_for_the_staff_only(): void
    {
        $this->active();
        $this->as($this->sara);
        $projectThread = (string) $this->postJson($this->url('/threads'), ['project' => $this->project->uuid])->assertOk()->json('data.thread.uuid');
        $general = $this->general();
        $dispute = $this->dispute();

        $kept = $this->say($this->lina, $projectThread, 'We skipped the tests on purpose');
        $gone = $this->say($this->lina, $general, 'Not about the project');
        $this->as($this->lina);
        $this->deleteJson($this->url("/messages/{$kept}"))->assertOk();
        $this->deleteJson($this->url("/messages/{$gone}"))->assertOk();

        // The team never sees the text again.
        $this->getJson($this->url("/threads/{$projectThread}/messages"))->assertJsonPath('data.messages.0.kind', 'deleted')->assertJsonPath('data.messages.0.body', null);
        $this->assertNull(FreelanceInternalMessage::query()->findOrFail($gone)->body);
        // The staff deciding the dispute do.
        $this->as($this->admin(['freelance-internal-chat.view']));
        $this->getJson("/api/admin/v1/freelance-disputes/{$dispute->uuid}/internal-chat")->assertOk()
            ->assertJsonPath('data.threads.0.messages.0.body', 'We skipped the tests on purpose')->assertJsonPath('data.threads.0.messages.0.deleted', true);
    }

    public function test_the_texts_exist_in_both_languages(): void
    {
        foreach (['freelance.errors.internal_chat_project_invalid', 'freelance.errors.internal_chat_not_open', 'freelance.internal_message_sent', 'freelance.internal_message_deleted',
            'freelance.internal_chat_notice', 'notifications.freelance_internal_message_title', 'notifications.freelance_internal_message_body',
            'notifications.freelance_internal_chat_reviewed_title', 'notifications.freelance_internal_chat_reviewed_body'] as $key) {
            foreach (['ar', 'en'] as $locale) {
                $this->assertTrue(Lang::has($key, $locale, false), "{$key} is missing in {$locale}");
            }
        }
    }
}
